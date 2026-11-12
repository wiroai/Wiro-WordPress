=== Wiro AI ===
Contributors: wiroai
Tags: ai, featured image, alt text, image generator, text to speech
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Featured images, image edits, upscaling, background removal, alt text, narration and SEO suggestions with Wiro AI models, in the block editor.

== Description ==

Wiro AI brings image, text and speech models into WordPress. Make a featured image from the post title, edit an image with a sentence, write alt text, read a post aloud or get title and SEO suggestions, without leaving the editor. Every result lands in your Media Library or your post, and each request shows its price before you run it.

= Featured images =

* **From the post.** The Wiro AI panel turns the post title, or its first words, into a prompt you can edit. One click makes the image, saves it to the Media Library and sets it as the featured image.
* **Bulk featured images.** Wiro AI > Bulk images lists posts that have no featured image. Pick them, check the total price, confirm, and a queue makes and sets an image for each one.
* **Wiro AI Image block.** Describe an image anywhere in a post. When it is ready, the block becomes a regular Image block, so your posts keep their images even if you turn the plugin off.

= Image tools =

Open the W menu in the toolbar of any Image block, or the Wiro AI box on an image in the Media Library:

* **Edit with a prompt:** "make it a rainy evening", "put the bottle on a marble table".
* **Upscale** to a larger, sharper copy.
* **Remove the background** for a transparent PNG.
* **Write alt text** with a model that reads images, in your site's language.

Edits are saved as new images. The original stays in the Media Library, and the block change can be undone like any edit.

= Text and speech =

* **Titles, excerpt and SEO description.** An LLM reads the post and suggests three titles, an excerpt and a meta description of up to 155 characters. Apply a title or the excerpt with one click, or copy the description into your SEO plugin.
* **Narration.** Read the post aloud with a text-to-speech model and voice of your choice. The audio file goes to the Media Library and an audio player is added to the top or end of the post.

= Models =

Each task has its own model menu, filled from the Wiro AI catalog by category, with the price of each model next to its name. The recommended models come first, for example Nano Banana 2.1, GPT Image 2, Seedream 5 Pro and FLUX.2 Pro for images, ElevenLabs Eleven v4 and Gemini TTS for narration, and Gemini or GPT for alt text and suggestions. Pick a default per task in the settings and change it for any request.

= Control and privacy =

* **Price before you run.** Every tool shows one line with the price of the request, worked out from the model's price list and your settings, such as the number of characters to read.
* **Usage history.** Wiro AI > Usage lists every request with its user, model, cost and result, plus the spend this month, last month and all time.
* **Monthly limit.** Set a spending limit for the site; new requests stop when it is reached.
* **Who can use it.** Authors and up, editors and up, or administrators only. Bulk jobs, usage and settings stay with administrators.
* **The key stays on your server.** The editor only talks to your own site, and WordPress calls Wiro AI. You can keep the key in wp-config.php instead of the database.

= About Wiro AI =

[Wiro AI](https://wiro.ai) runs 1,000+ AI models for images, video, audio, 3D and text behind one API, with an LLM gateway, workflows and hosted agents on top. Browse the models at [wiro.ai/models](https://wiro.ai/models) and read the API docs at [wiro.ai/docs](https://wiro.ai/docs).

Development happens on GitHub: [wiroai/Wiro-WordPress](https://github.com/wiroai/Wiro-WordPress).

= Pricing =

The plugin is free. You need a Wiro AI account, and each request is billed to your Wiro AI project at the price of the model you pick. The plugin shows the price before each request and what it cost afterwards.

== Installation ==

1. In your WordPress admin, go to Plugins, Add New Plugin, search for "Wiro AI", then install and activate it.
2. Create a project at [wiro.ai/panel/project/new](https://wiro.ai/panel/project/new) and copy its API key. If you choose signature authentication, copy the API secret too.
3. Go to Wiro AI > Settings, paste the key and save. Click "Check connection" to confirm it works.
4. Open a post and click the W button in the top bar for the Wiro AI sidebar, select an Image block and open the W menu in its toolbar, or open an image in the Media Library.

To keep the key out of the database, add it to wp-config.php instead:

`define( 'WIRO_AI_API_KEY', 'your-api-key' );`

For signature-based projects, also add:

`define( 'WIRO_AI_API_SECRET', 'your-api-secret' );`

== Frequently Asked Questions ==

= Do I need a Wiro AI account? =

Yes. Sign up at [wiro.ai](https://wiro.ai), create a project and paste its API key into Wiro AI > Settings. Requests are billed to that project.

= Which models can I use? =

Every task lists the models of its category in the Wiro AI catalog: text to image, image editing, upscaling, background removal and text to speech, plus the LLMs of the Wiro AI gateway for alt text and suggestions. A few recommended models come first in each menu.

= How is the price worked out? =

From the model's own price list and the settings of your request, the way Wiro AI bills it: per image, per character of narration, per megapixel, or per token for LLMs. Models billed by run time show an estimate. Wiro AI > Usage shows what each request actually cost.

= Who can use Wiro AI on my site? =

Choose in Wiro AI > Settings: authors, editors and administrators; editors and administrators; or administrators only. Users also need to be able to edit the post or image they work on. Developers can change the capability with the `wiro_ai_generate_capability` filter.

= Can I cap the spending? =

Yes. Set a monthly limit in dollars in Wiro AI > Settings. When this month's spend on the site reaches it, new requests stop until the next month or until you raise the limit.

= Where are the images and audio files stored? =

In your Media Library, like any upload. Edited, upscaled and background-free images are saved as new files next to the original.

= What happens to my content if I deactivate the plugin? =

Nothing. Featured images, Image blocks and Audio blocks are regular WordPress content. A Wiro AI Image block that was never used to generate an image shows nothing on the site.

= Does it work with the Classic Editor? =

The editor tools need the block editor. The Media Library tools, the bulk screen and the usage history work either way.

= Can developers change the models or requests? =

Yes. The `wiro_ai_presets` filter sets values sent to a model for a task, `wiro_ai_run_params` changes the parameters of each request, and `wiro_ai_generate_capability` changes who can use the plugin.

= What does it cost? =

The plugin is free. Each request costs what the model's page on [wiro.ai/models](https://wiro.ai/models) lists, and the price line shows it before you run. For example, alt text with a small LLM is a fraction of a cent, and an image is a few cents.

== Screenshots ==

1. The Wiro AI sidebar: a featured image from the post title, with the model and the price.
2. Title, excerpt and SEO description suggestions.
3. Narration: an audio player at the top of the post, with the voice and the price.
4. The Wiro AI menu on an Image block: edit, upscale, remove the background, write alt text.
5. Wiro AI tools on an image in the Media Library.
6. Bulk featured images for posts that have none.
7. Usage history with the cost of every request.
8. Settings: a default model for each task, a monthly limit and who can use Wiro AI.

== External services ==

This plugin connects to two Wiro AI services. It sends nothing until you add a Wiro AI API key.

**Wiro AI API (api.wiro.ai)** runs the image, image editing, upscaling, background removal and text-to-speech models.

* When: when a user starts one of those tasks, every few seconds while the task runs (to check its progress), when the plugin loads the model lists and model settings (cached for 12 hours), and when an administrator clicks "Check connection".
* What is sent: the API key (plus a signature made with the API secret, for signature-based projects), the chosen model and its settings, and the input of the task: the prompt for new images and edits, the image file for edits, upscaling and background removal (uploaded to your Wiro AI account's file storage), and the post title and text for narration.
* What comes back: the task's progress and the address of the result, which WordPress downloads from Wiro AI's file servers (such as cdn1.wiro.ai) into the Media Library.

**Wiro AI LLM gateway (llm.wiro.ai)** answers the alt text and the title, excerpt and SEO suggestions.

* When: when a user asks for alt text or suggestions, and when the plugin loads the list of LLMs (cached for 12 hours).
* What is sent: the API key, the chosen model and the input: a smaller copy of the image for alt text, or the post title and up to 6,000 characters of its text for suggestions.
* What comes back: the answer text and its cost.

The plugin sends no other content, user data or site address.

Wiro AI terms of service: https://wiro.ai/agreement/terms-of-service
Wiro AI privacy policy: https://wiro.ai/agreement/privacy-policy

== Changelog ==

= 1.1.0 =
* Image tools on Image blocks and in the Media Library: edit with a prompt, upscale, remove the background, write alt text.
* Narration: read a post aloud with a text-to-speech model and add an audio player.
* Title, excerpt and SEO description suggestions.
* Bulk featured images for posts without one.
* Model menus for every task, filled from the Wiro AI catalog, with prices.
* A price line before every request, usage history, a monthly spending limit and an access setting.
* The Wiro AI sidebar in the editor and a Wiro AI admin menu.

= 1.0.0 =
* First release: featured image panel, Wiro AI Image block, settings page with a connection check.

== Upgrade Notice ==

= 1.1.0 =
Adds image editing, upscaling, background removal, alt text, narration, SEO suggestions, bulk featured images and usage history.
