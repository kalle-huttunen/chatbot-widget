// Chat widget. Mounts itself on the page, keeps the conversation in
// sessionStorage and talks to api.php in the same directory as this file.
(function () {
  'use strict';

  const script = document.currentScript;
  const BASE = script && script.src ? new URL('.', script.src).href : '/chatbot/';
  const API_URL = BASE + 'api.php';
  const BOT_AVATAR = BASE + 'images/bot-avatar.svg';
  const MAX_MESSAGE_LENGTH = 500; // keep in sync with api.php
  const MAX_HISTORY = 50;         // keep in sync with api.php
  const STORE_OPEN = 'oac.open';
  const STORE_CONVERSATION = 'oac.conversation';

  const STRINGS = {
    en: {
      title: 'Assistant',
      toggle: 'Chat',
      open: 'Open chat',
      minimize: 'Minimize chat',
      close: 'Close chat and delete the conversation',
      placeholder: 'Type your message...',
      send: 'Send',
      greeting: 'Hi! How can I help you?',
      suggestions: ['What services do you offer?', 'How can I contact you?'],
      typing: 'Typing...',
      errorGeneric: 'Sorry, something went wrong.',
      errorNetwork: 'Connection error. Please try again later.',
      closeConfirm: 'This will close the chat and delete the conversation history. Continue?',
      confirmYes: 'Yes',
      confirmNo: 'No'
    },
    fi: {
      title: 'Avustaja',
      toggle: 'Chat',
      open: 'Avaa chat',
      minimize: 'Pienennä chat',
      close: 'Sulje chat ja poista keskustelu',
      placeholder: 'Kirjoita viestisi...',
      send: 'Lähetä',
      greeting: 'Hei! Kuinka voin auttaa sinua?',
      suggestions: ['Minkälaisia palveluita tarjoatte?', 'Miten teihin saa yhteyden?'],
      typing: 'Kirjoittaa...',
      errorGeneric: 'Pahoittelut, jokin meni pieleen.',
      errorNetwork: 'Yhteysvirhe. Yritä myöhemmin uudelleen.',
      closeConfirm: 'Tämä sulkee keskustelun ja poistaa keskusteluhistorian. Haluatko jatkaa?',
      confirmYes: 'Kyllä',
      confirmNo: 'Ei'
    }
  };

  /* Language comes from <html lang>: "en-US" uses the "en" texts. Unknown
     languages fall back to English. window.oacChatbot.strings overrides texts. */
  const LANG = (document.documentElement.lang || '').toLowerCase().split('-')[0];
  const CONFIG = window.oacChatbot || {};
  const STR = Object.assign({}, STRINGS[LANG] || STRINGS.en, (CONFIG.strings || {})[LANG]);

  /* sessionStorage throws when the browser blocks storage. The chat then
     works for the current page view and forgets the conversation on navigation. */
  const store = {
    get(key) {
      try { return sessionStorage.getItem(key); } catch (e) { return null; }
    },
    set(key, value) {
      try { sessionStorage.setItem(key, value); } catch (e) { /* see above */ }
    },
    remove(key) {
      try { sessionStorage.removeItem(key); } catch (e) { /* see above */ }
    }
  };

  function el(tag, props, children) {
    const node = document.createElement(tag);
    Object.entries(props || {}).forEach(([key, value]) => {
      if (key === 'text') node.textContent = value;
      else node.setAttribute(key, value);
    });
    (children || []).forEach(child => node.appendChild(child));
    return node;
  }

  function formatTime(date) {
    return date.getHours().toString().padStart(2, '0') + ':' +
           date.getMinutes().toString().padStart(2, '0');
  }

  /* Adds text to a node as text nodes, with http(s) URLs as links.
     Nothing is parsed as HTML, so model output cannot inject markup. */
  function appendLinkedText(parent, text) {
    const pattern = /https?:\/\/[^\s<>"']+/g;
    let last = 0;
    let match;
    while ((match = pattern.exec(text))) {
      const url = match[0].replace(/[.,;:!?)\]]+$/, '');
      parent.appendChild(document.createTextNode(text.slice(last, match.index)));
      parent.appendChild(el('a', { href: url, target: '_blank', rel: 'noopener noreferrer', text: url }));
      last = match.index + url.length;
    }
    parent.appendChild(document.createTextNode(text.slice(last)));
  }

  class Chatbot {
    constructor() {
      this.conversation = [];
      this.busy = false;       // a request is in flight
      this.confirming = false; // the close confirmation is showing
      this.session = 0;        // bumped when the conversation is cleared
      this.request = null;
      this.build();
    }

    build() {
      this.toggle = el('button', { id: 'oac-chatbot-toggle', type: 'button', 'aria-controls': 'oac-chatbot' });
      this.minimize = el('button', { id: 'oac-minimize', type: 'button', 'aria-label': STR.minimize, title: STR.minimize, text: '−' });
      this.close = el('button', { id: 'oac-close', type: 'button', 'aria-label': STR.close, title: STR.close, text: '×' });
      this.chatWindow = el('div', { id: 'oac-chat-window', role: 'log', 'aria-live': 'polite' });
      this.input = el('textarea', {
        id: 'oac-user-input',
        rows: '1',
        maxlength: String(MAX_MESSAGE_LENGTH),
        placeholder: STR.placeholder,
        'aria-label': STR.placeholder
      });
      this.sendButton = el('button', { id: 'oac-send-button', type: 'button', text: STR.send });
      this.panel = el('div', { id: 'oac-chatbot', role: 'dialog', 'aria-label': STR.title }, [
        el('div', { id: 'oac-chatbot-header' }, [
          el('span', { class: 'oac-title', text: STR.title }),
          el('div', { class: 'oac-header-buttons' }, [this.minimize, this.close])
        ]),
        this.chatWindow,
        el('div', { id: 'oac-input-area' }, [this.input, this.sendButton])
      ]);
      document.body.append(this.toggle, this.panel);
    }

    init() {
      this.restore();
      this.bind();
      this.setOpen(store.get(STORE_OPEN) === 'true', false);
    }

    restore() {
      try {
        const parsed = JSON.parse(store.get(STORE_CONVERSATION) || '[]');
        if (!Array.isArray(parsed)) return;
        this.conversation = parsed.filter(msg =>
          msg && (msg.role === 'user' || msg.role === 'assistant') &&
          typeof msg.content === 'string' && typeof msg.t === 'number');
      } catch (e) {
        this.conversation = [];
      }
    }

    isOpen() {
      return this.panel.classList.contains('oac-open');
    }

    setOpen(open, focus) {
      this.panel.classList.toggle('oac-open', open);
      this.toggle.textContent = open ? '×' : STR.toggle;
      this.toggle.setAttribute('aria-expanded', String(open));
      this.toggle.setAttribute('aria-label', open ? STR.minimize : STR.open);
      store.set(STORE_OPEN, String(open));
      if (open) this.render();
      if (focus) (open ? this.input : this.toggle).focus();
    }

    updateControls() {
      const locked = this.busy || this.confirming;
      this.input.disabled = locked;
      this.sendButton.disabled = locked;
    }

    scrollToBottom() {
      this.chatWindow.scrollTop = this.chatWindow.scrollHeight;
    }

    render() {
      this.chatWindow.replaceChildren();
      this.confirming = false; // the confirmation bubble was just removed
      if (this.conversation.length === 0) this.showGreeting();
      else this.conversation.forEach(msg => this.renderMessage(msg));
      if (this.busy) this.showTyping();
      this.updateControls();
      this.scrollToBottom();
    }

    botMessage(bubble, time) {
      const avatar = el('div', { class: 'oac-avatar' }, [
        el('img', { src: BOT_AVATAR, alt: '' }),
        el('div', { class: 'oac-status' })
      ]);
      const children = [el('div', { class: 'oac-row' }, [avatar, bubble])];
      if (time) children.push(time);
      return el('div', { class: 'oac-message oac-bot' }, children);
    }

    renderMessage(msg) {
      const bubble = el('div', { class: 'oac-bubble' });
      const time = el('div', { class: 'oac-time', text: formatTime(new Date(msg.t)) });
      if (msg.role === 'user') {
        bubble.textContent = msg.content;
        this.chatWindow.appendChild(el('div', { class: 'oac-message oac-user' }, [bubble, time]));
      } else {
        appendLinkedText(bubble, msg.content);
        this.chatWindow.appendChild(this.botMessage(bubble, time));
      }
    }

    addMessage(role, content) {
      const msg = { role: role, content: content, t: Date.now() };
      this.conversation.push(msg);
      store.set(STORE_CONVERSATION, JSON.stringify(this.conversation));
      this.renderMessage(msg);
      this.scrollToBottom();
    }

    showGreeting() {
      this.chatWindow.appendChild(this.botMessage(el('div', { class: 'oac-bubble', text: STR.greeting })));
      const suggestions = Array.isArray(STR.suggestions) ? STR.suggestions : [];
      if (suggestions.length === 0) return;
      this.chatWindow.appendChild(el('div', { class: 'oac-suggestions' },
        suggestions.map(text => el('button', { class: 'oac-suggestion', type: 'button', text: text }))));
    }

    showTyping() {
      const message = this.botMessage(el('div', { class: 'oac-bubble oac-typing', text: STR.typing }));
      message.classList.add('oac-typing-row');
      this.chatWindow.appendChild(message);
      this.scrollToBottom();
    }

    removeTyping() {
      const row = this.chatWindow.querySelector('.oac-typing-row');
      if (row) row.remove();
    }

    /* Errors are shown but not stored, so they are never sent to the model as history. */
    showError(text) {
      this.chatWindow.appendChild(this.botMessage(el('div', { class: 'oac-bubble oac-error', text: text })));
      this.scrollToBottom();
    }

    async send(text) {
      const fromInput = text === undefined;
      text = (fromInput ? this.input.value : text).trim();
      if (!text || this.busy || this.confirming) return;
      if (fromInput) {
        this.input.value = '';
        this.input.style.height = '';
      }

      const suggestions = this.chatWindow.querySelector('.oac-suggestions');
      if (suggestions) suggestions.remove();

      // The server appends the new message itself, so the history stops before it.
      const history = this.conversation.slice(-MAX_HISTORY)
        .map(msg => ({ role: msg.role, content: msg.content }));
      const session = this.session;
      this.addMessage('user', text);
      this.busy = true;
      this.updateControls();
      this.showTyping();

      let reply = null;
      let error = STR.errorGeneric;
      this.request = new AbortController();
      try {
        const res = await fetch(API_URL, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ message: text, conversation: history, lang: LANG }),
          signal: this.request.signal
        });
        const data = await res.json();
        if (data.success && data.data && typeof data.data.reply === 'string') reply = data.data.reply;
        else if (typeof data.error === 'string') error = data.error;
      } catch (e) {
        error = STR.errorNetwork;
      }

      this.busy = false;
      this.removeTyping();
      this.updateControls();
      if (session !== this.session) return; // the conversation was cleared while waiting
      if (reply) this.addMessage('assistant', reply);
      else this.showError(error);
      if (this.isOpen()) this.input.focus();
    }

    askClose() {
      if (this.confirming) return;
      this.confirming = true;
      this.updateControls();

      const yes = el('button', { type: 'button', text: STR.confirmYes });
      const no = el('button', { type: 'button', text: STR.confirmNo });
      const row = el('div', { class: 'oac-message oac-bot' }, [
        el('div', { class: 'oac-bubble oac-confirm' }, [
          el('span', { text: STR.closeConfirm }),
          el('div', { class: 'oac-confirm-buttons' }, [yes, no])
        ])
      ]);
      yes.addEventListener('click', () => {
        this.clear();
        this.setOpen(false, true);
      });
      no.addEventListener('click', () => {
        row.remove();
        this.confirming = false;
        this.updateControls();
        this.input.focus();
      });
      this.chatWindow.appendChild(row);
      this.scrollToBottom();
      no.focus();
    }

    clear() {
      this.session++;
      if (this.request) this.request.abort();
      this.conversation = [];
      store.remove(STORE_CONVERSATION);
      this.confirming = false;
      this.updateControls();
    }

    bind() {
      this.toggle.addEventListener('click', () => this.setOpen(!this.isOpen(), true));
      this.minimize.addEventListener('click', () => this.setOpen(false, true));
      this.close.addEventListener('click', () => this.askClose());
      this.panel.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') this.setOpen(false, true);
      });

      this.chatWindow.addEventListener('click', (e) => {
        if (e.target.classList.contains('oac-suggestion')) this.send(e.target.textContent);
      });

      this.sendButton.addEventListener('click', () => this.send());
      this.input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          this.send();
        }
      });

      // Grow the textarea with its content, up to the max-height in the CSS.
      this.input.addEventListener('input', () => {
        const border = this.input.offsetHeight - this.input.clientHeight;
        this.input.style.height = 'auto';
        this.input.style.height = (this.input.scrollHeight + border) + 'px';
      });
    }
  }

  /* The widget is built only after its stylesheet has loaded, so it never
     shows unstyled. Works for a plain <script> tag and for dynamic loading. */
  function start() {
    const css = el('link', { rel: 'stylesheet', href: BASE + 'chatbot.css' });
    css.addEventListener('load', () => new Chatbot().init());
    css.addEventListener('error', () => console.error('[oac-chatbot] could not load ' + css.href));
    document.head.appendChild(css);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start);
  } else {
    start();
  }
})();
