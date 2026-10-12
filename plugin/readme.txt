=== Formulario Mensaje ===
Contributors: tu-usuario
Tags: contact form, form builder, forms, email, webhooks
Requires at least: 7.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Lightweight, auditable form builder: your own fields, email notifications, webhooks, an inbox for messages and built-in spam protection.

== Description ==

Formulario Mensaje is a form builder for people who want to know what their forms do. Everything a form needs is in the plugin, nothing is locked behind a paid version, and no data leaves your site unless you set it up yourself.

**Build the form**

* A builder in four steps: the questions, what happens when a message arrives, the look, and where to publish it.
* Fields: text, email, phone, text area, number, date, web address, dropdown, radio buttons, checkboxes, privacy consent, hidden fields and file upload, plus headings, paragraphs and dividers.
* Columns, starter templates, duplicating a form, and export and import as JSON (without secrets).
* Conditional logic: show a field, a step or send a notification only when the answers meet your rules.
* Multi-step forms with a progress bar and validation on each step.
* A style panel for each form. Without changes, the form takes the fonts and colors of your theme.

**Publish it**

* A block for the WordPress editor, a shortcode and a widget for Elementor.
* The form is rendered on the server with accessible markup, and it keeps working in browsers without JavaScript.

**Receive the messages**

* An inbox in the WordPress dashboard: read and unread messages, search, filters, reply by email and export to CSV. It also works on tablets and phones.
* Several email notifications for each form, each one with its own recipients and condition, in plain text or in HTML with your logo and color.
* Emails are sent with WordPress, with your own SMTP server or with Resend.
* Webhooks that send each message to another application, in the background, with retries, custom headers and an HMAC signature.
* A delivery log: when a notification or a webhook fails, you see it and you can retry it.

**Spam protection and privacy**

* Every form is protected without a captcha: a hidden trap field, a minimum time to fill it in and a limit on submissions per IP address.
* If you still receive spam, you can add Cloudflare Turnstile, hCaptcha or Google reCAPTCHA v3.
* You decide what is stored: whether messages are stored at all, whether the IP address is stored complete, anonymized or not at all, and after how many days messages are deleted.
* Uploaded files are stored in a private folder and are only downloaded by logged-in users with permission, or through a signed link that expires.
* Passwords and API keys are stored encrypted and are never shown again.

**For developers**

* WP-CLI commands (`wp formulario-mensaje`) to list, export and import forms, export messages and process the queue.
* Read-only abilities for the WordPress Abilities API.
* Actions and filters to add your own field types, actions and captcha services, and to change how the plugin behaves.

The plugin is translated into Spanish (Spain).

== Installation ==

1. Download `formulario-mensaje-<version>.zip` from the [latest release on GitHub](https://github.com/alvarofontela/formulario-mensaje/releases/latest).
2. In WordPress, go to Plugins > Add Plugin > Upload Plugin, choose that file and activate the plugin.
3. Go to Forms and create your first form, from a template or from a blank form.
4. Put it on a page with the Form block, with the Elementor widget or with the code shown in the Publish step.

From then on, new versions appear in the Plugins screen and in Dashboard > Updates like those of any other plugin, and you can turn on automatic updates for it.

Formulario Mensaje cannot be network activated in a multisite network: activate it on each site instead.

== Frequently Asked Questions ==

= Does it send my data anywhere? =

No. Nothing that your visitors or you write leaves your site unless you set it up: a captcha service, Resend, your SMTP server or the address of a webhook. The only connection the plugin makes on its own is to GitHub, to check whether there is a new version, and it sends no data about your site. See the External services section.

= How do I get updates? =

From the Plugins screen and from Dashboard > Updates, like any other plugin. The plugin is published on GitHub, not in the WordPress.org directory: when WordPress checks for updates, the plugin asks GitHub for its latest release. Versions marked as pre-release on GitHub are never offered.

= Do I need a captcha? =

Usually not. Every form is already protected by a hidden trap field, a minimum time to fill it in and a limit on submissions per IP address. Add a captcha only if spam still gets through.

= Does it work without JavaScript? =

Yes. A visitor without JavaScript can still send the form, with a stricter limit on submissions. You can turn that off in the settings, and a form protected with a captcha always needs JavaScript.

= Where are the messages stored? =

In your own WordPress database, in the tables of the plugin. Each form decides whether its messages are stored and for how long.

= What happens to the data when I delete the plugin? =

Nothing is deleted unless you ask for it: there is a setting to delete forms, messages and settings when the plugin is deleted. Stored passwords and API keys are always deleted.

= Can I change or add a translation? =

Yes. The plugin uses the standard WordPress translation files. A translation placed in `wp-content/languages/plugins/` takes priority over the one in the plugin.

== External services ==

The plugin connects to GitHub to check for its own updates. Every other service below is only used after an administrator turns it on and enters its keys or its address in the plugin.

= GitHub (plugin updates) =

The plugin is distributed through GitHub, a service of GitHub, Inc. When WordPress checks for plugin updates (about twice a day, and when you open the Plugins or Updates screens), your server downloads a small file from `github.com/alvarofontela/formulario-mensaje` that describes the latest release: its version, its requirements and its list of changes. The request carries no data about your site, your forms or your visitors, and it identifies itself only with the name and version of the plugin; like any connection, GitHub sees the IP address of your server. When you install an update, WordPress downloads the package of that release from the same GitHub project. To turn the check off, return `false` from the `formulario_mensaje_updates_enabled` filter.

[Terms of service](https://docs.github.com/en/site-policy/github-terms/github-terms-of-service) · [Privacy statement](https://docs.github.com/en/site-policy/privacy-policies/github-general-privacy-statement)

= Cloudflare Turnstile =

An optional captcha, provided by Cloudflare, that checks whether a visitor is a person. When it is chosen in Forms > Settings > Captcha, the Turnstile script is loaded from `challenges.cloudflare.com` in the visitor's browser when they start filling in a form, and Cloudflare receives the data it collects there (such as the IP address and browser details). When the form is sent, your server sends the token of that check, your secret key and the visitor's IP address to Cloudflare to verify it.

[Terms of use](https://www.cloudflare.com/website-terms/) · [Privacy policy](https://www.cloudflare.com/privacypolicy/) · [Turnstile privacy addendum](https://www.cloudflare.com/turnstile-privacy-policy/)

= hCaptcha =

An optional captcha, provided by Intuition Machines, Inc. When it is chosen in the settings, the hCaptcha script is loaded from `js.hcaptcha.com` in the visitor's browser when they start filling in a form, and hCaptcha receives the data it collects there (such as the IP address and browser details). When the form is sent, your server sends the token of that check, your secret key and the visitor's IP address to `api.hcaptcha.com` to verify it.

[Terms of service](https://www.hcaptcha.com/terms) · [Privacy policy](https://www.hcaptcha.com/privacy)

= Google reCAPTCHA v3 =

An optional captcha, provided by Google. When it is chosen in the settings, the reCAPTCHA script is loaded from `www.google.com` in the visitor's browser when they start filling in a form, and Google receives the data it collects there (such as the IP address, cookies and browser details). When the form is sent, your server sends the token of that check, your secret key and the visitor's IP address to Google to verify it.

[Terms of service](https://policies.google.com/terms) · [Privacy policy](https://policies.google.com/privacy)

= Resend =

An optional email delivery service. When it is chosen in Forms > Settings > Email, each notification is sent through the Resend API (`api.resend.com`) instead of through WordPress. Resend receives your API key and the whole email: sender, recipients, subject, the content of the message (which includes what the visitor wrote) and its attachments.

[Terms of service](https://resend.com/legal/terms-of-service) · [Privacy policy](https://resend.com/legal/privacy-policy)

= Your own SMTP server and webhooks =

If you enter an SMTP server, notifications are sent through it. If you add a webhook to a form, the data of each message sent with that form is sent to the address you entered. Both are services you choose, and their terms are the ones you have with them.

== Source code ==

The dashboard application and the block are written in React and compiled with `@wordpress/scripts`. Their readable source code is included in the `src` folder of the plugin, and the compiled files are in `build`. To build them again, run this in the plugin folder:

`npm install @wordpress/scripts --no-save`
`npx wp-scripts build --source-path=src --output-path=build`

The script of the form in the front end (`assets/js`) and its styles (`assets/css`) are not compiled or minified.

== Changelog ==

= 1.0.0 =
* First stable version.
* Updates from the WordPress dashboard: new versions published on GitHub appear in the Plugins and Updates screens.

= 0.14.0 =
* First public version.
