<?php
// Chatbot configuration template.
// Copy this file to config.php and fill in real values. Keep config.php out of
// version control. The values stay on the server and are never sent to the browser.

// OpenAI API key (required).
define('OAC_OPENAI_API_KEY', '');

// Chat Completions model. Model names change over time, so check the current
// list: https://developers.openai.com/api/docs/models
define('OAC_OPENAI_MODEL', 'gpt-6-luna');

// Reasoning effort sent with the request. 'none' keeps replies fast and cheap on
// models that accept it. Set to '' to leave the parameter out of the request.
define('OAC_OPENAI_REASONING_EFFORT', 'none');

// Upper bound for the tokens generated per reply. On reasoning models this also
// covers hidden reasoning tokens, so raise it if you turn reasoning on.
define('OAC_OPENAI_MAX_TOKENS', 200);

// HubSpot private app token with read and write access to contacts.
// Leave empty to skip HubSpot.
define('OAC_HUBSPOT_TOKEN', '');

// Where to mail a notice when a visitor shares contact details.
// Leave the address empty to skip the mail.
define('OAC_NOTIFICATION_EMAIL', '');
define('OAC_NOTIFICATION_FROM', 'noreply@example.com');

// What counts as a phone number in a visitor's message. The default takes
// numbers that start with + or 0 and hold 7 to 15 digits. Adjust it for your
// country: a looser pattern catches more numbers, and also more dates and
// order numbers.
define('OAC_PHONE_PATTERN', '/(?<![\w.])(?:\+|0)[\d\s().-]{5,20}\d(?!\w)/');

// Language used when the page language has no system prompt below.
define('OAC_DEFAULT_LANG', 'en');

// System prompt per language. The widget sends the language of the page
// (<html lang>). The assistant knows about your business only what you write here.
define('OAC_SYSTEM_PROMPTS', [
    'en' => <<<'PROMPT'
You are the virtual assistant of Example Company. Give clear and useful information about the company's services, and guide the conversation politely toward the visitor sharing an email address or phone number so that someone can follow up. Reply politely, in plain text without Markdown, and keep your answers under 70 words.

Contact information:
- Email: hello@example.com
- Phone: +1 555 0100
- Contact form: https://www.example.com/contact

About the company:
Describe here what the company does, its services and prices, and anything else the assistant should be able to answer.

Collecting contact details:
Ask for an email address or phone number when the visitor wants more information or help. Tell them the details are handled confidentially. When they share them, thank them and say someone will be in touch soon.
PROMPT,

    'fi' => <<<'PROMPT'
Olet Esimerkkiyritys Oy:n virtuaaliavustaja. Anna selkeää ja hyödyllistä tietoa yrityksen palveluista ja ohjaa keskustelu ystävällisesti siihen, että kävijä jakaa sähköpostiosoitteensa tai puhelinnumeronsa yhteydenottoa varten. Vastaa kohteliaasti, pelkkänä tekstinä ilman Markdown-muotoilua, ja pidä vastauksesi enintään 70 sanan pituisina.

Yhteystiedot:
- Sähköposti: hello@example.com
- Puhelin: +1 555 0100
- Yhteydenottolomake: https://www.example.com/contact

Tietoa yrityksestä:
Kuvaa tähän mitä yritys tekee, sen palvelut ja hinnat sekä muu tieto, johon avustajan pitää osata vastata.

Yhteystietojen kerääminen:
Pyydä sähköpostiosoite tai puhelinnumero, kun kävijä haluaa lisätietoja tai apua. Kerro, että tiedot käsitellään luottamuksellisesti. Kun kävijä antaa ne, kiitä ja kerro, että häneen ollaan pian yhteydessä.
PROMPT,
]);
