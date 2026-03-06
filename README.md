# PHP Prisma

Light-weight PHP package for integrating multi-media and text related Large Language Models (LLMs) into your applications using a unified interface.

<nav>
<div class="method-header"><a href="#supported-providers">Supported providers</a></div>
<ul class="method-list">
    <li><a href="#audio">Audio</a></li>
    <li><a href="#image">Image</a></li>
    <li><a href="#text">Text</a></li>
    <li><a href="#video">Video</a></li>
</ul>
<div class="method-header"><a href="#api-usage">API usage</a></div>
<ul class="method-list">
    <li><a href="#ensure">ensure</a><span>: Ensures that the provider has implemented the method</span></li>
    <li><a href="#has">has</a><span>: Tests if the provider has implemented the method</span></li>
    <li><a href="#model">model</a><span>: Use the model passed by its name</span></li>
    <li><a href="#withClientOptions">withClientOptions</a><span>: Add options for the Guzzle HTTP client</span></li>
    <li><a href="#withSystemPrompt">withSystemPrompt</a><span>: Add a system prompt for the LLM</span></li>
    <li><a href="#response-objects">Response objects</a><span>: How data is returned by the API</span></li>
</ul>
<div class="method-header"><a href="#audio-api">Audio API</a></div>
<ul class="method-list">
    <li><a href="#demix">demix</a><span>: Separate an audio file into its individual tracks</span></li>
    <li><a href="#denoise">denoise</a><span>: Remove noise from an audio file</span></li>
    <li><a href="#describe">describe</a><span>: Describe the content of an audio file</span></li>
    <li><a href="#revoice">revoice</a><span>: Exchange the voice in an audio file</span></li>
    <li><a href="#speak">speak</a><span>: Convert text to speech in an audio file</span></li>
    <li><a href="#transcribe">transcribe</a><span>: Converts speech of an audio file to text</span></li>
</ul>
<div class="method-header"><a href="#image-api">Image API</a></div>
<ul class="method-list">
    <li><a href="#background">background</a><span>: Replace background according to the prompt</span></li>
    <li><a href="#describe">describe</a><span>: Describe the content of an image</span></li>
    <li><a href="#detext">detext</a><span>: Remove all text from the image</span></li>
    <li><a href="#erase">erase</a><span>: Erase parts of the image</span></li>
    <li><a href="#imagine">imagine</a><span>: Generate an image from the prompt</span></li>
    <li><a href="#inpaint">inpaint</a><span>: Edit an image area according to a prompt</span></li>
    <li><a href="#isolate">isolate</a><span>: Remove the image background</span></li>
    <li><a href="#relocate">relocate</a><span>: Place the foreground object on a new background</span></li>
    <li><a href="#repaint">repaint</a><span>: Repaint an image according to the prompt</span></li>
    <li><a href="#uncrop">uncrop</a><span>: Extend/outpaint the image</span></li>
    <li><a href="#upscale">upscale</a><span>: Scale up the image</span></li>
    <li><a href="#vectorize">vectorize</a><span>: Creates embedding vectors from images</span></li>
</ul>
<div class="method-header"><a href="#text-api">Text API</a></div>
<ul class="method-list">
    <li><a href="#translate">translate</a><span>: Translate texts from one language to another</span></li>
</ul>
<div class="method-header"><a href="#video-api">Video API</a></div>
<ul class="method-list">
    <li><a href="#describe">describe</a><span>: Describe the content of a video</span></li>
</ul>
<div class="method-header"><a href="#custom-providers">Custom providers</a></div>
<ul class="method-list">
    <li><a href="#base-skeleton">Base skeleton</a></li>
    <li><a href="#requests">Requests</a></li>
    <li><a href="#responses">Responses</a></li>
    <li><a href="#examples">Examples</a></li>
</ul>
</nav>

## Supported providers

- [Alibaba](https://www.alibabacloud.com/help/en/model-studio/model-api-reference/)
- [AudioPod AI](https://audiopod.ai/)
- [Bedrock Titan (AWS)](https://docs.aws.amazon.com/bedrock/latest/userguide/titan-models.html)
- [Black Forest Labs](https://docs.bfl.ai/quick_start/introduction)
- [Clipdrop](https://clipdrop.co/apis)
- [Cohere](https://docs.cohere.com/docs/the-cohere-platform)
- [DeepL](https://developers.deepl.com/docs)
- [Deepgram](https://deepgram.com/)
- [ElevenLabs](https://elevenlabs.io/docs/overview/intro)
- [Gemini (Google)](https://aistudio.google.com/models/gemini-2-5-flash-image)
- [Google Translate](https://cloud.google.com/translate/docs/reference/rest/v2/translate)
- [Groq](https://groq.com/)
- [Ideogram](https://ideogram.ai/api)
- [Mistral](https://docs.mistral.ai/api)
- [Murf](https://murf.ai/api)
- [OpenAI](https://openai.com/api/)
- [RemoveBG](https://www.remove.bg/api)
- [StabilityAI](https://platform.stability.ai/)
- [VertexAI (Google)](https://cloud.google.com/vertex-ai/generative-ai/docs)
- [VoyageAI](https://docs.voyageai.com/)

### Audio

|                       | demix | denoise | describe | revoice | speak | transcribe |
| :---                  | :---: | :---:   | :---:    | :---:   | :---: | :---:      |
| **Alibaba**           | -     | -       | -        | -       | yes   | -          |
| **AudioPod**          | yes   | yes     | -        | yes     | yes   | yes        |
| **Deepgram**          | -     | -       | -        | -       | yes   | yes        |
| **ElevenLabs**        | -     | -       | -        | yes     | yes   | yes        |
| **Gemini**            | -     | -       | yes      | -       | -     | -          |
| **Groq**              | -     | -       | yes      | -       | yes   | yes        |
| **Mistral**           | -     | -       | yes      | -       | -     | yes        |
| **Murf**              | -     | -       | -        | yes     | yes   | -          |
| **OpenAI**            | -     | -       | yes      | -       | yes   | yes        |

### Image

|                       | background | describe | detext | erase | imagine | inpaint | isolate | recognize | relocate | repaint | uncrop | upscale | vectorize |
| :---                  | :---:      | :---:    | :---:  | :---: | :---:   | :---:   | :---:   | :---:     | :---:    | :---:   | :---:  | :---:   | :---:     |
| **Alibaba**           | -          | -        | -      | -     | yes     | -       | -       | -         | -        | -       | -      | -       | yes       |
| **Bedrock Titan**     | -          | -        | -      | -     | yes     | yes     | yes     | -         | -        | -       | -      | -       | yes       |
| **Black Forest Labs** | -          | -        | -      | -     | beta    | beta    | -       | -         | -        | -       | beta   | -       | -         |
| **Clipdrop**          | yes        | -        | yes    | yes   | yes     | -       | yes     | -         | -        | -       | yes    | yes     | -         |
| **Cohere**            | -          | -        | -      | -     | -       | -       | -       | -         | -        | -       | -      | -       | yes       |
| **Gemini**            | -          | yes      | -      | -     | yes     | -       | -       | -         | -        | yes     | -      | -       | -         |
| **Groq**              | -          | yes      | -      | -     | -       | -       | -       | -         | -        | -       | -      | -       | -         |
| **Ideogram**          | beta       | beta     | -      | -     | beta    | beta    | -       | -         | -        | beta    | -      | beta    | -         |
| **Mistral**           | -          | -        | -      | -     | -       | -       | -       | yes       | -        | -       | -      | -       | -         |
| **OpenAI**            | -          | yes      | -      | -     | yes     | yes     | -       | -         | -        | -       | -      | -       | -         |
| **RemoveBG**          | -          | -        | -      | -     | -       | -       | yes     | -         | yes      | -       | -      | -       | -         |
| **StabilityAI**       | -          | -        | -      | yes   | yes     | yes     | yes     | -         | -        | -       | yes    | yes     | -         |
| **VertexAI**          | -          | -        | -      | -     | yes     | yes     | -       | -         | -        | -       | -      | yes     | yes       |
| **VoyageAI**          | -          | -        | -      | -     | -       | -       | -       | -         | -        | -       | -      | -       | yes       |

### Text

|                       | translate |
| :---                  | :---:     |
| **DeepL**             | yes       |
| **Google**            | yes       |

### Video

|                       | describe |
| :---                  | :---:    |
| **Gemini**            | yes      |

## Installation

```
composer req aimeos/prisma
```

## API usage

Basic usage:

```php
use Aimeos\Prisma\Prisma;

$image = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->model( '<modelname>' ) // if model can be selected
    ->ensure( 'imagine' ) // make sure interface is implemented
    ->imagine( 'a grumpy cat' )
    ->binary();

$texts = Prisma::text()
    ->using( 'deepl', ['api_key' => 'xxx'])
    ->ensure( 'translate' )
    ->translate( ['Hello'], 'de' )
    ->texts();
```

### ensure

Ensures that the provider has implemented the method.

```php
public function ensure( string $method ) : self
```

* @param **string** `$method` Method name
* @return **Provider**
* @throws \Aimeos\Prisma\Exceptions\NotImplementedException

**Example:**

```php
\Aimeos\Prisma\Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->ensure( 'imagine' );
```

### has

Tests if the provider has implemented the method.

```php
public function has( string $method ) : bool
```

* @param **string** `$method` Method name
* @return **bool** TRUE if implemented, FALSE if absent

**Example:**

```php
\Aimeos\Prisma\Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->has( 'imagine' );
```

### model

Use the model passed by its name.

Used if the provider supports more than one model and allows to select
between the different models. Otherwise, it's ignored.

```php
public function model( ?string $model ) : self
```

* @param **string&#124;null** `$model` Model name
* @return **self** Provider interface

**Example:**

```php
\Aimeos\Prisma\Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->model( 'dall-e-3' );
```

### withClientOptions

Add options for the Guzzle HTTP client.

```php
public function withClientOptions( array `$options` ) : self
```

* @param **array&#60;string, mixed&#62;** `$options` Associative list of name/value pairs
* @return **self** Provider interface

**Example:**

```php
\Aimeos\Prisma\Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->withClientOptions( ['timeout' => 120] );
```

### withSystemPrompt

Add a system prompt for the LLM.

It may be used by providers supporting system prompts. Otherwise, it's
ignored.

```php
public function withSystemPrompt( ?string $prompt ) : self
```

* @param **string&#124;null** `$prompt` System prompt
* @return **self** Provider interface

**Example:**

```php
\Aimeos\Prisma\Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->withSystemPrompt( 'You are a professional illustrator' );
```

### Response objects

The methods return a *FileResponse*, *TextResponse* or *VectorResponse* object that
contains the returned data with optional meta/usage/description information.

**FileResponse** objects:

```php
$base64 = $response->base64(); // first base64 data, from binary, base64 and URL, waits for async requests
$file = $response->binary(); // first binary data, from binary, base64 and URL, waits for async requests
$url = $response->url(); // first URL, only if URLs are returned, otherwise NULL
$mime = $response->mimetype(); // image mime type, waits for async requests
$text = $response->description(); // image description if returned by provider
$bool = $response->ready(); // FALSE for async APIs until file is available
$file = $response->first(); // first available file object
$array = $response->files(); // all available file objects

// loop over all available files
foreach( $response as $name => $file ) {
    $file->binary()
}
```

URLs are automatically converted to binary and base64 data if requested and conversion between
binary and base64 data is done on request too.

**TextResponse** objects:

```php
$text = $response->text(); // first text content (non-streaming)
$text = $response->first(); // first available text
$texts = $response->texts(); // all texts (non-streaming)

// loop over all available texts
foreach( $response as $text ) {
    echo $text;
}
```

**VectorResponse** objects:

```php
$vector = $response->first(); // first embedding vector if only one file has been passed
$vectors = $response->vectors(); // embedding vectors for the passed files in the same order

// loop over all available vectors
foreach( $response as $vector ) {
    print_r( $vector );
}
```

Included **meta data** (optional):

```php
$meta = $response->meta();
```

It returns an associative array whose content totally depends on the provider.

Included **usage data** (optional):

```php
$usage = $response->usage();
```

It returns an associative array whose content depends on the provider. If the provider returns
usage information, the `used` array key is available and contains a number. What the number
represents depdends on the provider too.

## Audio API

### demix

Separate an audio file into its individual tracks.

```php
public function demix( Audio $audio, int $stems, array $options = [] ) : FileResponse
```

* @param **Audio** `$audio` Input audio object
* @param **int** `$stems` Number of stems to separate into (e.g. 2 for vocals and accompaniment)
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Audio file response

**Supported options:**

* AudioPod

### denoise

Remove noise from an audio file.

```php
public function denoise( Audio $audio, array $options = [] ) : FileResponse
```

* @param **Audio** `$audio` Input audio object
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Audio file response

**Supported options:**

* [AudioPod](https://docs.audiopod.ai/api-reference/noise-reduction)

### describe

Describe the content of an audio file.

```php
public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
```

* @param **Audio** `$audio` Input audio object
* @param **string&#124;null** `$lang` ISO language code the description should be generated in
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Response text

**Supported options:**

* Gemini
* Groq
* [OpenAI](https://platform.openai.com/docs/api-reference/audio/createTranscription)

### revoice

Exchange the voice in an audio file.

```php
public function revoice( Audio $audio, string $voice, array $options = [] ) : FileResponse;
```

* @param **Audio** `$audio` Input audio object
* @param **string** `$voice` Voice name or identifier
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Audio file response

**Supported options:**

* AudioPod
* [ElevenLabs](https://elevenlabs.io/docs/api-reference/speech-to-speech/convert)
* [Murf](https://murf.ai/api/docs/api-reference/voice-changer/convert)

### speak

Converts text to speech.

```php
public function speak( string $text, string $voice = , array $options = [] ) : FileResponse;
```

* @param **string** `$text` Text to be converted to speech
* @param **string&#124;null** `$voice` Voice identifier for speech synthesis
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Audio file response

**Supported options:**

* [Alibaba](https://www.alibabacloud.com/help/en/model-studio/qwen-tts-api)
* [AudioPod](https://docs.audiopod.ai/api-reference/text-to-speech#generate-speech)
* [Deepgram](https://developers.deepgram.com/reference/text-to-speech/speak-request)
* [ElevenLabs](https://elevenlabs.io/docs/api-reference/text-to-speech/convert)
* Groq
* [Murf](https://murf.ai/api/docs/api-reference/text-to-speech/generate)
* [OpenAI](https://platform.openai.com/docs/api-reference/audio/createSpeech)

### transcribe

Converts speech to text.

```php
public function transcribe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
```

* @param **Audio** `$audio` Input audio object
* @param **string&#124;null** `$lang` ISO language code of the audio content
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Transcription text response

**Supported options:**

* [AudioPod](https://docs.audiopod.ai/api-reference/speech-to-text)
* [Deepgram](https://developers.deepgram.com/reference/text-to-speech/speak-request)
* [ElevenLabs](https://elevenlabs.io/docs/api-reference/speech-to-text/convert)
* Groq
* [Mistral](https://docs.mistral.ai/api/endpoint/audio/transcriptions)
* [OpenAI](https://platform.openai.com/docs/api-reference/audio/createTranscription)

## Image API

Most methods require an image object as input which contains a reference to the image that
should be processed. This object can be created by:

```php
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.php', 'image/png' );
$image = Image::fromLocalPath( 'path/to/image.png', 'image/png' );
$image = Image::fromBinary( 'PNG...', 'image/png' );
$image = Image::fromBase64( 'UE5H...', 'image/png' );

// Laravel only:
$image = Image::fromStoragePath( 'path/to/image.png', 'public', 'image/png' );
```

The last parameter of all methods (mime type) is optional. If it's not passed, the file
content will be retrieved to determine the mime type if reqested.

**Note:** It's best to use **fromUrl()** if possible because all other formats (binary and
base64) can be derived from the URL content but URLs can't be created from binary/base64
data.

### background

Replace image background with a background described by the prompt.

```php
public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **string** `$prompt` Prompt describing the new background
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* Clipdrop
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/replace-background-v3#request)
* [VertexAI](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/imagen-product-recontext-api#parameters)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->background( $image, 'Golden sunset on a caribbean beach' );

$image = $fileResponse->binary();
```

### describe

Describe the content of an image.

```php
public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
```

* @param **Image** `$image` Input image object
* @param **string&#124;null** `$lang` ISO language code the description should be generated in
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Response text

**Supported options:**

* Gemini
* Groq
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/describe#request)
* OpenAI

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$textResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->describe( $image, 'de' );

$text = $textResponse->text();
```

### detext

Remove all text from the image.

```php
public function detext( Image $image, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* Clipdrop

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->detext( `$image` );

$image = $fileResponse->binary();
```

### erase

Erase parts of the image.

```php
public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **Image** `$mask` Mask image object
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

The mask must be an image with black parts (#000000) to keep and white parts (#FFFFFF)
to remove.

**Supported options:**

* [Clipdrop](https://clipdrop.co/apis/docs/cleanup)
* [StabilityAI](https://platform.stability.ai/docs/api-reference#tag/Edit/paths/~1v2beta~1stable-image~1edit~1erase/post)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );
$mask = Image::fromBinary( 'PNG...' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->erase( $image, $mask );

$image = $fileResponse->binary();
```

### imagine

Generate an image from the prompt.

```php
public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
```

* @param **string** `$prompt` Prompt describing the image
* @param **array&#60;int, \Aimeos\Prisma\Files\Image&#62;** `$images` Associative list of file name/Image instances
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* [Alibaba Qwen/Wan/Z-Image](https://www.alibabacloud.com/help/en/model-studio/qwen-image-api)
* [Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-titan-image.html)
* [Black Forest Labs](https://docs.bfl.ai/api-reference/models/generate-or-edit-an-image-with-flux2-[pro])
* Clipdrop
* [Gemini](https://ai.google.dev/gemini-api/docs/image-generation#optional_configurations)
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/generate-v3#request)
* [VertexAI](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/imagen-api#generate_images)
* [OpenAI GPT image 1](https://platform.openai.com/docs/guides/image-generation?image-generation-model=gpt-image-1#customize-image-output)
* [OpenAI Dall-e-3](https://platform.openai.com/docs/guides/image-generation?image-generation-model=dall-e-3#customize-image-output)
* [OpenAI Dall-e-2](https://platform.openai.com/docs/guides/image-generation?image-generation-model=dall-e-2#customize-image-output)
* [StabilityAI Core](https://platform.stability.ai/docs/api-reference#tag/Generate/paths/~1v2beta~1stable-image~1generate~1core/post)
* [StabilityAI Ultra](https://platform.stability.ai/docs/api-reference#tag/Generate/paths/~1v2beta~1stable-image~1generate~1ultra/post)
* [StabilityAI Stable Diffusion 3.5](https://platform.stability.ai/docs/api-reference#tag/Generate/paths/~1v2beta~1stable-image~1generate~1sd3/post)

**Example:**

```php
use Aimeos\Prisma\Prisma;

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->imagine( 'Futuristic robot looking at a dashboard' );

$image = $fileResponse->binary();
```

### inpaint

Edit an image by inpainting an area defined by a mask according to a prompt.

```php
public function inpaint( Image $image, Image $mask, string $prompt, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **Image** `$mask` Input mask image object
* @param **string** `$prompt` Prompt describing the changes
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

The mask must be an image with black parts (#000000) to keep and white parts (#FFFFFF)
to edit.

**Supported options:**

* [Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-titan-image.html)
* [Black Forest Labs](https://docs.bfl.ai/api-reference/models/generate-an-image-with-flux1-fill-[pro]-using-an-input-image-and-mask)
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/edit-v3#request)
* [VertexAI](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/imagen-api-edit#parameters)
* [OpenAI GPT image 1](https://platform.openai.com/docs/guides/image-generation?image-generation-model=gpt-image-1#customize-image-output)
* [OpenAI Dall-e-3](https://platform.openai.com/docs/guides/image-generation?image-generation-model=dall-e-3#customize-image-output)
* [OpenAI Dall-e-2](https://platform.openai.com/docs/guides/image-generation?image-generation-model=dall-e-2#customize-image-output)
* [StabilityAI](https://platform.stability.ai/docs/api-reference#tag/Edit/paths/~1v2beta~1stable-image~1edit~1inpaint/post)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );
$mask = Image::fromBinary( 'PNG...' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->inpaint( $image, $mask, 'add a pink flamingo' );

$image = $fileResponse->binary();
```

### isolate

Remove the image background.

```php
public function isolate( Image $image, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* [Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-titan-image.html)
* [Clipdrop](https://clipdrop.co/apis/docs/remove-background)
* [RemoveBG](https://www.remove.bg/api#api-reference)
* [StabilityAI](https://platform.stability.ai/docs/api-reference#tag/Edit/paths/~1v2beta~1stable-image~1edit~1remove-background/post)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->isolate( `$image` );

$image = $fileResponse->binary();
```

### recognize

Recognizes the text in the given image (OCR).

```php
public function recognize( Image $image, array $options = [] ) : TextResponse;
```

* @param **Image** `$image` Input image object
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Response text object

**Supported options:**

* [Mistral](https://docs.mistral.ai/api/endpoint/ocr#operation-ocr_v1_ocr_post)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$textTesponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->recognize( `$image` );

$text = $textResponse->text();
```

### relocate

Place the foreground object on a new background.

```php
public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image with foreground object
* @param **Image** `$bgimage` Background image
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* [RemoveBG](https://www.remove.bg/api#api-reference)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );
$bgimage = Image::fromUrl( 'https://example.com/background.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->relocate( $image, $bgimage );

$image = $fileResponse->binary();
```

### repaint

Repaint an image according to the prompt.

```php
public function repaint( Image $image, string $prompt, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **string** `$prompt` Prompt describing the changes
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* [Gemini](https://ai.google.dev/gemini-api/docs/image-generation#optional_configurations)
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/remix-v3#request)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->repaint( $image, 'Use a van Goch style' );

$image = $fileResponse->binary();
```

### uncrop

Extend/outpaint the image.

```php
public function uncrop( Image $image,  int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **int** `$top` Number of pixels to extend to the top
* @param **int** `$right` Number of pixels to extend to the right
* @param **int** `$bottom` Number of pixels to extend to the bottom
* @param **int** `$left` Number of pixels to extend to the left
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* [Black Forest Labs](https://docs.bfl.ai/api-reference/models/expand-an-image-by-adding-pixels-on-any-side)
* Clipdrop
* [StabilityAI](https://platform.stability.ai/docs/api-reference#tag/Edit/paths/~1v2beta~1stable-image~1edit~1outpaint/post)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->uncrop( $image, 100, 200, 0, 50 );

$image = $fileResponse->binary();
```

### upscale

Scale up the image.

```php
public function upscale( Image $image, int $factor, array $options = [] ) : FileResponse
```

* @param **Image** `$image` Input image object
* @param **int** `$factor` Upscaling factor between 2 and the maximum value supported by the provider
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **FileResponse** Response file

**Supported options:**

* Clipdrop
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/upscale#request)
* [VertexAI](https://docs.cloud.google.com/vertex-ai/generative-ai/docs/model-reference/imagen-upscale-api#parameters)
* [StabilityAI](https://platform.stability.ai/docs/api-reference#tag/Upscale)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$fileResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->upscale( $image, 4 );

$image = $fileResponse->binary();
```

### vectorize

Creates embedding vectors of the images' content.

```php
public function vectorize( array $images, ?int $size = null, array $options = [] ) : VectorResponse
```

* @param **array&#60;int, \Aimeos\Prisma\Files\Image&#62;** `$images` List of input image objects
* @param **int&#124;null** `$size` Size of the resulting vector or null for provider default
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **VectorResponse** Response vector object

**Supported options:**

* [Alibaba](https://www.alibabacloud.com/help/en/model-studio/multimodal-embedding-api-reference)
* Bedrock
* [Cohere](https://docs.cohere.com/reference/embed#request)
* VertexAI
* [VoyageAI](https://docs.voyageai.com/reference/multimodal-embeddings-api)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$images = [
    Image::fromUrl( 'https://example.com/image.png' ),
    Image::fromUrl( 'https://example.com/image2.png' ),
];

$vectorResponse = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->vectorize( $images, 512 );

$vectors = $vectorResponse->vectors();
```

## Text API

### translate

Translate one or more texts from one language to another.

```php
public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse
```

* @param **array&#60;string&#62;** `$texts` Input texts to be translated
* @param **string** `$to` ISO language code to translate the text into
* @param **string&#124;null** `$from` ISO language code of the input text (optional, auto-detected if omitted)
* @param **string&#124;null** `$context` Context for the translation (optional)
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Response text

**Supported options:**

* [DeepL](https://developers.deepl.com/docs/api-reference/translate/openapi-spec-for-text-translation)
* [Google](https://docs.cloud.google.com/translate/docs/reference/rest/v2/translate#authorization)

**Example:**

```php
use Aimeos\Prisma\Prisma;

$textResponse = Prisma::text()
    ->using( 'deepl', ['api_key' => 'xxx'])
    ->ensure( 'translate' )
    ->translate( ['Hello', 'World'], 'de', 'en' );

$texts = $textResponse->texts(); // ['Hallo', 'Welt']
```

## Video API

### describe

Describe the content of a video file.

```php
public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
```

* @param **Video** `$video` Input video object
* @param **string&#124;null** `$lang` ISO language code the description should be generated in
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Response text

**Supported options:**

* Gemini

## Custom providers

### Base skeleton

```php
<?php

// for Audio providers
namespace Aimeos\Prisma\Providers\Audio;
// for Image providers
namespace Aimeos\Prisma\Providers\Image;
// for Text providers
namespace Aimeos\Prisma\Providers\Text;
// for Video providers
namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;


class Myprovider extends Base implements ...
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        // if authentication is done via headers
        $this->header( '<api key name>', $config['api_key'] );
        // base url for all requests (no paths)
        $this->baseUrl( '<provider URL>' );
    }
```

Depending on the provider type (Audio, Image, Text or Video), you can implement one or
more of the available interfaces for that provider type:

- [Audio](https://github.com/aimeos/prisma/tree/master/src/Contracts/Audio)
- [Image](https://github.com/aimeos/prisma/tree/master/src/Contracts/Image)
- [Text](https://github.com/aimeos/prisma/tree/master/src/Contracts/Text)
- [Video](https://github.com/aimeos/prisma/tree/master/src/Contracts/Video)

For example:

```php
namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Describe
{
    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        // ...
    }
}
```

### Requests

There are a few support methods available to simplify building requests which
are sent by the Guzzle client to the server of the AI provider.

First, you should validate and limit the passed options to the ones supported
by the AI API. The *allow()* and *sanitze()* methods filter out unsupported
values because different APIs have different parameters but users of the
Prisma API should be able to pass parameters for several AI providers at once.
Therefore, get only supported parameters and values using:

```php
// filter key/value pairs in $options and use the ones allowed by the API
$allowed = $this->allow( $options, ['<key1>', '<key2>', /* ... */] );

// filter values to pass only allowed option values (optional)
$allowed = $this->sanitize( $allowed, ['<key1>' => ['<val1>', '<val2>', '<val3>']])
```

If the user can choose between several LLM models when using the API, the
*modelName()* method will return the users choice or the passed default value:

```php
$model = $this->modelName( 'gemini-2.5-flash' );
```

To build the request in the correct format (form key/value pairs, multipart or
JSON data), the *request()* method transforms parameters and files for form and
multipart requests. JSON data is specific to the API and you must create it
yourself:

```php
// Form data request
$data = $this->request( params );
// Multipart request
$data = ['multipart' => $this->request( params, ['image_key' = $image->binary()] )];
// JSON request
$data = ['json' => ['image_key' = array_map( fn( $image ) => $image->base64()] + params];
```

Then, you can send the request using the Guzzle client, validate the response
and get the returned content:

```php
// use Guzzle to send the request and get the response from the server
$response = $this->client()->post( 'relative/api/path', $data );

// validates HTTP status codes, overwrite if needed
$this->validate( $response );

// get binary content or JSON content
$content = $response->getBody()->getContents();
```

A full example would be:

```php
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\TextResponse;

public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
{
    $model = $this->modelName( 'flash' );
    $allowed = $this->allow( $options, ['version'] );

    $params = ['language' => $lang] + $allowed;
    $data = ['multipart' => $this->request( params, ['file' = $image->binary()] )];
    $response = $this->client()->post( 'relative/api/path', $data );

    $this->validate( $response );

    $content = $response->getBody()->getContents();
    // return a response
}
```

### Responses

There are several response types available, which can be returned depending on
the implemented interfaces:

* FileResponse
* TextResponse
* VectorResponse

#### File response

A FileResponse can contain one or more files, either as binary or base64 data,
or as remote URL. Passing the mime type is optional but prevents guessing the
file type later:

```php
use Aimeos\Prisma\Responses\FileResponse;

$response = FileResponse::fromBinary( '...', 'image/png' );
$response = FileResponse::fromBase64( '...', 'image/png' );
$response = FileResponse::fromUrl( '...', 'image/png' );
```

You can also add more than one file by using the *add()* method:

```php
use Aimeos\Prisma\Files\File;

$response->add( File::fromBinary( '...', 'image/png' ) );
```

If the API processes requests asynchronously, the *fromAsync()* method accepts
a closure function and the optional timeout between requests as second parameter:

```php
$client = $this->client();
$response = FileResponse::fromAsync( function( FileResponse $fr ) uses ( $client ) {
    // download or add file(s) to file response object
    $fr->add( File::fromUrl( '...', 'image/png' ) );
}, 3 );
```

#### Text response

The TextResponse can contain one or more texts and is created by using:

```php
use Aimeos\Prisma\Responses\TextResponse;

$response = TextResponse::fromText( '...' );
$response->add( '...' ); // add more texts
```

TextResponse objects also support *fromAsync()* for asynchronous APIs using a
closure function and the optional timeout between requests as second parameter:

```php
$client = $this->client();
$response = TextResponse::fromAsync( function( FileResponse $fr ) uses ( $client ) {
    // download and add texts to text response object
    $fr->add( '...' );
}, 3 );
```

#### Vector response

The *vectorize()* method returns a *VectorResponse* object which contains the
vector of float numbers representing the input:

```php
use Aimeos\Prisma\Responses\VectorResponse;

$response = VectorResponse::fromVectors( [
    [0.27629, 0.89271, 0.98265, /* ... */],
    /* ... */
] );
```

#### Meta data

All response objects support adding usage information and meta data if they are
returned by the provider API. Use the *withUsage() and *withMeta()* methods to
pass that information as part of the response object:

```php
$response->withUsage( // optional
    100, // used tokens, credits, etc. if available or NULL
    [] // arbitrary key/value pairs for the rest of the usage information
);
$response->withMeta( // optional
    [] // arbitrary meta data as key/value pairs, can be nested
);
```

TextResponse objects can store structured data e.g. returned when audio files
are transcribed:

```php
$response->withStructured( [
    // for transcriptions
    ['start' => 0.0, 'end' => 1.0, 'text' => 'This is a test.'],
    // ...
] );
```

Transcriptions must always contain the keys *start* and *end* in seconds as well
as *text* for the content in each entry (but there can be more key/value pairs
if available).

The FileResponse object also supports *withDescription()* to attach the file
description as string to the response object:

```php
$response->withDescription( '...' );
```

### Examples

#### Audio provider

```php
<?php

namespace Aimeos\Prisma\Providers\Audio;

use Aimeos\Prisma\Contracts\Audio\Describe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Audio;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Describe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-api-key', $config['api_key'] );
        $this->baseUrl( 'https://ai.com' );
    }


    public function describe( Audio $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allow( $options, ['version'] );
        $model = $this->modelName( 'flash' );

        $params = ['language' => $lang, 'model' => model] + $allowed;
        $data = ['multipart' => $this->request( params, ['file' = $audio->binary()] )];
        $response = $this->client()->post( 'relative/api/path', $data );

        $this->validate( $response );

        $data = $this->fromJson( $response );

        return TextResponse::fromText( @$data['text'] )
            ->withStructured( // optional
                $data['segments'] ?? []
            )
            ->withUsage( // optional
                @$data['usage']['total'],
                $data['usage'] ?? []
            )
            ->withMeta( // optional
                $data['meta'] ?? []
            );
    }
}
```

### Image provider

```php
<?php

namespace Aimeos\Prisma\Providers\Image;

use Aimeos\Prisma\Contracts\Image\Describe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Describe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-api-key', $config['api_key'] );
        $this->baseUrl( 'https://ai.com' );
    }


    public function describe( Image $audio, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allow( $options, ['version'] );
        $model = $this->modelName( 'flash' );

        $params = ['language' => $lang, 'model' => model] + $allowed;
        $data = ['multipart' => $this->request( params, ['file' = $audio->binary()] )];
        $response = $this->client()->post( 'relative/api/path', $data );

        $this->validate( $response );

        $data = $this->fromJson( $response );

        return TextResponse::fromText( @$data['text'] )
            ->withStructured( // optional
                $data['segments'] ?? []
            )
            ->withUsage( // optional
                @$data['usage']['total'],
                $data['usage'] ?? []
            )
            ->withMeta( // optional
                $data['meta'] ?? []
            );
    }
}
```

### Text provider

```php
<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Translate;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Translate
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( 'https://ai.com' );
    }


    public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse
    {
        $payload = [
            'texts' => $texts,
            'target_lang' => $to,
            'source_lang' => $from
        ] + $ $this->allowed( $options, ['formality'] );

        $response = $this->client()->post( '/v1/translate', ['json' => $payload] );

        $this->validate( $response );

        $data = $this->fromJson( $response );
        $translated = array_map( fn( $item ) => $item['text'] ?? '', $data ?? [] );

        return TextResponse::fromTexts( $translated )
            ->withUsage( // optional
                $data['usage']['total'] ?? 0,
                $data['usage'] ?? []
            )
            ->withMeta( // optional
                $data['meta'] ?? []
            );
    }
}
```

### Video provider

```php
<?php

namespace Aimeos\Prisma\Providers\Video;

use Aimeos\Prisma\Contracts\Video\Describe;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Video;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Describe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( sprintf( 'No API key' ) );
        }

        $this->header( 'x-api-key', $config['api_key'] );
        $this->baseUrl( 'https://ai.com' );
    }


    public function describe( Video $video, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allow( $options, ['version'] );
        $model = $this->modelName( 'flash' );

        $params = ['language' => $lang, 'model' => model] + $allowed;
        $data = ['multipart' => $this->request( params, ['file' = $video->binary()] )];
        $response = $this->client()->post( 'relative/api/path', $data );

        $this->validate( $response );

        $data = $this->fromJson( $response );

        return TextResponse::fromText( @$data['text'] )
            ->withStructured( // optional
                $data['segments'] ?? []
            )
            ->withUsage( // optional
                @$data['usage']['total'],
                $data['usage'] ?? []
            )
            ->withMeta( // optional
                $data['meta'] ?? []
            );
    }
}
```
