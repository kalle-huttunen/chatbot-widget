# Website chatbot widget

A small chat widget for a website. The front end is plain JavaScript and CSS with no dependencies. The back end is a single PHP file that forwards the conversation to the OpenAI Chat Completions API, so the API key stays on the server.

When a visitor types an email address or a phone number, the back end can save it as a HubSpot contact and mail you a notice.

## Requirements

- PHP 8.0 or newer with the `curl` and `mbstring` extensions
- An OpenAI API key
- Optional: a HubSpot private app token, and a server where PHP `mail()` works

## Install

1. Copy the `chatbot/` directory to your site. The directory can have any name and location: the script finds its own files.
2. Copy `chatbot/config.example.php` to `chatbot/config.php` and fill it in.
3. Add one line before `</body>` on each page that should show the widget:

   ```html
   <script src="/chatbot/chatbot.js" defer></script>
   ```

To try it locally, run `php -S localhost:8000` in this directory and open `http://localhost:8000/demo.html`.

## Configuration

Everything on the server side is in `chatbot/config.php`.

| Constant | Purpose |
|---|---|
| `OAC_OPENAI_API_KEY` | OpenAI API key. Required. |
| `OAC_OPENAI_MODEL` | Chat Completions model name. |
| `OAC_OPENAI_REASONING_EFFORT` | Sent as `reasoning_effort`. An empty string leaves the parameter out. |
| `OAC_OPENAI_MAX_TOKENS` | Sent as `max_completion_tokens`. |
| `OAC_HUBSPOT_TOKEN` | HubSpot private app token. Empty skips HubSpot. |
| `OAC_NOTIFICATION_EMAIL` | Where the notice about new contact details is mailed. Empty skips the mail. |
| `OAC_NOTIFICATION_FROM` | From address of that mail. |
| `OAC_PHONE_PATTERN` | Regular expression that decides what counts as a phone number. |
| `OAC_DEFAULT_LANG` | Language used when the page language has no system prompt. |
| `OAC_SYSTEM_PROMPTS` | System prompt per language. |

The system prompt is the only thing the assistant knows about your business. Write your services, prices and contact details into it.

The request to OpenAI carries `model`, `messages`, `max_completion_tokens` and `reasoning_effort`. It sends no `temperature`. If you pick a model that rejects `reasoning_effort`, set `OAC_OPENAI_REASONING_EFFORT` to an empty string.

### Language

The widget reads the page language from `<html lang>` and sends it with each message. `en-US` counts as `en`. English and Finnish texts are built in. A page in another language gets the English texts and the prompt of `OAC_DEFAULT_LANG`.

### Texts

Set `window.oacChatbot` before the script tag to change any text, per language:

```html
<script>
  window.oacChatbot = {
    strings: {
      en: {
        title: 'Acme Assistant',
        greeting: 'Hi! Ask me anything about Acme.',
        suggestions: ['What does it cost?', 'How do I get started?']
      }
    }
  };
</script>
```

The full list of keys is in the `STRINGS` table at the top of `chatbot/chatbot.js`. To add a language, give all the keys for it here, and add its system prompt to `config.php`. Error texts that come from the server are in `OAC_MESSAGES` in `chatbot/api.php`.

### Colours and position

Override the CSS variables on `:root` in your own stylesheet:

```css
:root {
  --oac-accent: #4f8cff;
  --oac-bg: #ffffff;
  --oac-offset-bottom: 110px; /* lift the widget above a cookie banner */
}
```

All variables are listed at the top of `chatbot/chatbot.css`. Replace `chatbot/images/bot-avatar.svg` to change the avatar.

## How it works

The server keeps no conversation state. The browser stores the conversation in `sessionStorage`, so it survives page changes within a tab and disappears when the tab closes. Each request sends the new message together with the 50 newest earlier messages.

Limits enforced by the server:

- 500 characters per visitor message
- 50 earlier messages per request, each cut to a maximum length
- 20 requests per minute per IP address

Contact details are saved before the model is asked, so a failed model call does not lose them. The model is told whether saving worked, and it asks the visitor to get in touch directly when it did not.

## Security and privacy

- `config.php` holds your keys. The bundled `.htaccess` blocks it on Apache. nginx ignores `.htaccess`, so add a matching `location` rule there.
- The rate limit counts by `REMOTE_ADDR`. Behind a reverse proxy or a CDN every visitor shares the proxy's address, so adjust `oac_rate_limit_ok()` in `api.php` for that setup.
- The rate limit does not stop someone who uses many addresses. Set a monthly spending limit in your OpenAI account.
- The conversation history comes from the browser, and a visitor can edit it. Put nothing into the system prompt that a visitor must not see, and do not let the model's replies trigger actions.
- Visitor messages go to OpenAI. Contact details go to HubSpot and to your mailbox. Say so in your privacy policy.

## Licence

MIT, see [LICENSE](LICENSE).
