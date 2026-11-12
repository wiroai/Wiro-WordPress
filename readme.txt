=== Wiro AI ===
Contributors: wiroai
Tags: ai, featured image, image generator, text to image, ai image
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate featured images and in-post images from your post title or content with Wiro AI models like Nano Banana, GPT Image 2 and FLUX.2.

== Description ==

Wiro AI adds image generation to the block editor. Write a prompt, or build one from the post title or content, pick a model, and the finished image lands in your Media Library, ready to use as the featured image.

= Features =

* **Featured images from your post.** The Wiro AI panel in the Post sidebar turns the post title, or the first words of the post, into a prompt you can edit. One click generates the image, saves it to the Media Library and sets it as the featured image.
* **Wiro AI Image block.** Add the block anywhere in a post and describe the image. When it is ready, the block becomes a regular Image block, so your posts keep their images even if you turn the plugin off.
* **Choose the model.** Nano Banana 2.1, Nano Banana Pro, GPT Image 2, Seedream 5 Pro, FLUX.2 Pro, Grok Imagine Image 2, Qwen Image 3.0 and P-Image are in the menu. You can add any other text-to-image model from the Wiro AI catalog by its slug.
* **Aspect ratios** from wide 16:9 to tall 9:16. The plugin sends the ratio in the form each model expects.
* **Tidy Media Library entries.** Files are named after the post title, the alt text is filled in, and the prompt is saved in the image description.
* **The key stays on your server.** The editor only talks to your own site, and WordPress calls the Wiro AI API. You can also keep the key in wp-config.php instead of the database.
* **Prompt template.** Set how the "From title" and "From content" buttons write the prompt, for example to ask for a house style.

= About Wiro AI =

[Wiro AI](https://wiro.ai) runs 1,000+ AI models for images, video, audio, 3D and text behind one API, with an LLM gateway, workflows and hosted agents on top. This plugin uses its text-to-image models. Browse them at [wiro.ai/models](https://wiro.ai/models) and read the API docs at [wiro.ai/docs](https://wiro.ai/docs).

Development happens on GitHub: [wiroai/Wiro-WordPress](https://github.com/wiroai/Wiro-WordPress).

= Pricing =

The plugin is free. You need a Wiro AI account, and each image is billed to your Wiro AI project at the price listed on the model's page. The panel shows what each image cost once it is ready.

== Installation ==

1. In your WordPress admin, go to Plugins, Add New, search for "Wiro AI", then install and activate it.
2. Create a project at [wiro.ai/panel/project/new](https://wiro.ai/panel/project/new) and copy its API key. If you choose signature authentication, copy the API secret too.
3. Go to Settings > Wiro AI, paste the key and save. Click "Check connection" to confirm it works.
4. Open a post, open the Post sidebar and find the "Wiro AI featured image" panel, or add a "Wiro AI Image" block to the post.

To keep the key out of the database, add it to wp-config.php instead:

`define( 'WIRO_AI_API_KEY', 'your-api-key' );`

For signature-based projects, also add:

`define( 'WIRO_AI_API_SECRET', 'your-api-secret' );`

== Frequently Asked Questions ==

= Do I need a Wiro AI account? =

Yes. Sign up at [wiro.ai](https://wiro.ai), create a project and paste its API key into Settings > Wiro AI. Images are billed to that project.

= Which models can I use? =

The menu lists eight image models: Nano Banana 2.1, Nano Banana Pro, GPT Image 2, Seedream 5 Pro, FLUX.2 Pro, Grok Imagine Image 2, Qwen Image 3.0 and P-Image. To use another one, find a text-to-image model at [wiro.ai/models](https://wiro.ai/models) and type the owner/model part of its address, such as `google/nano-banana-2-1`, into "Other model" on the settings page. It then shows up in the editor's model menu too.

= Who can generate images? =

Users who can upload files (Authors and above, by default) and who can edit the post. Images are billed to the site's Wiro AI project, so you can limit this with the `wiro_ai_generate_capability` filter. For example, to allow only administrators:

`add_filter( 'wiro_ai_generate_capability', function () { return 'manage_options'; } );`

= Where are the images stored? =

In your Media Library, like any upload. The plugin downloads each finished image from Wiro AI into WordPress and attaches it to the post.

= What happens to my images if I deactivate the plugin? =

Nothing. Featured images and Image blocks are regular WordPress content. A Wiro AI Image block that was never used to generate an image shows nothing on the site.

= Does it work with the Classic Editor? =

No. The panel and the block need the block editor.

= Can developers change the model list or the request? =

Yes. The `wiro_ai_models` filter changes the models in the menu, and the `wiro_ai_run_params` filter changes the parameters sent to Wiro AI for each image.

= What does an image cost? =

The plugin is free. Each image costs what the model's page on [wiro.ai/models](https://wiro.ai/models) lists, and the price depends on the model and its settings. The editor shows the cost of each image after it is made.

== Screenshots ==

1. The Wiro AI featured image panel in the Post sidebar, with a prompt built from the post title.
2. The generated image, saved to the Media Library and set as the featured image.
3. The Wiro AI Image block in a post.
4. Settings > Wiro AI.

== External services ==

This plugin connects to the Wiro AI API to generate images. It sends nothing until you add a Wiro AI API key.

* **Service:** the Wiro AI API at api.wiro.ai, provided by Wiro AI (https://wiro.ai).
* **When it connects:** when a user clicks "Generate image" in the Wiro AI panel or the Wiro AI Image block, every few seconds while that image is being made (to check its progress), and when an administrator clicks "Check connection" on the settings page.
* **What it sends:** the API key (plus a signature made with the API secret, for signature-based projects), the prompt, the chosen model and the image settings, such as the aspect ratio. The prompt is the text in the prompt box, which includes the post title and the first words of the post when you use the "From title" or "From content" buttons. The plugin also reads the public settings of the chosen model from the same API. It sends no other post content, user data or site address.
* **What it receives:** the progress of the image and the address of the finished file. WordPress then downloads the file from Wiro AI's file servers (such as cdn1.wiro.ai) into the Media Library.

Wiro AI terms of service: https://wiro.ai/agreement/terms-of-service
Wiro AI privacy policy: https://wiro.ai/agreement/privacy-policy

== Changelog ==

= 1.0.0 =
* First release: featured image panel, Wiro AI Image block, eight image models plus any model by slug, settings page with a connection check.

== Upgrade Notice ==

= 1.0.0 =
First release.
