# PHP Prisma

Light-weight PHP package for integrating multi-media related Large Language Models (LLMs) into your applications using a unified interface.

<nav>
<div class="method-header"><a href="#supported-providers">Supported providers</a></div>
<ul class="method-list">
    <li><a href="#images">Images</a></li>
</ul>
<div class="method-header"><a href="#api-usage">API usage</a></div>
<ul class="method-list">
    <li><a href="#ensure">ensure</a><span>: Ensures that the provider has implemented the method</span></li>
    <li><a href="#has">has</a><span>: Tests if the provider has implemented the method</span></li>
    <li><a href="#model">model</a><span>: Use the model passed by its name</span></li>
    <li><a href="#withClientOptions">withClientOptions</a><span>: Add options for the Guzzle HTTP client</span></li>
    <li><a href="#withSystemPrompt">withSystemPrompt</a><span>: Add a system prompt for the LLM</span></li>
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
</nav>

## Supported providers

- [Bedrock Titan (AWS)](https://docs.aws.amazon.com/bedrock/latest/userguide/titan-models.html)
- [Clipdrop](https://clipdrop.co/apis)
- [Cohere](https://docs.cohere.com/docs/the-cohere-platform)
- [Gemini (Google)](https://aistudio.google.com/models/gemini-2-5-flash-image)
- [Ideogram](https://ideogram.ai/api)
- [Mistral](https://docs.mistral.ai/api)
- [OpenAI](https://openai.com/api/)
- [RemoveBG](https://www.remove.bg/api)
- [StabilityAI](https://platform.stability.ai/)
- [VertexAI (Google)](https://cloud.google.com/vertex-ai/generative-ai/docs)
- [VoyageAI](https://docs.voyageai.com/)

### Images

|                   | background | describe | detext | erase | imagine | inpaint | isolate | recognize | relocate | repaint | uncrop | upscale | vectorize |
| :---              | :---:      | :---:    | :---:  | :---: | :---:   | :---:   | :---:   | :---:     | :---:    | :---:   | :---:  | :---:   | :---:     |
| **Bedrock Titan** | -          | -        | -      | -     | yes     | yes     | yes     | -         | -        | -       | -      | -       | yes       |
| **Clipdrop**      | yes        | -        | yes    | yes   | yes     | -       | yes     | -         | -        | -       | yes    | yes     | -         |
| **Cohere**        | -          | -        | -      | -     | -       | -       | -       | -         | -        | -       | -      | -       | yes       |
| **Gemini**        | -          | yes      | -      | -     | yes     | -       | -       | -         | -        | yes     | -      | -       | -         |
| **Ideogram**      | beta       | beta     | -      | -     | beta    | beta    | -       | -         | -        | beta    | -      | beta    | -         |
| **Mistral**       | -          | -        | -      | -     | -       | -       | -       | yes       | -        | -       | -      | -       | -         |
| **OpenAI**        | -          | yes      | -      | -     | yes     | yes     | -       | -         | -        | -       | -      | -       | -         |
| **RemoveBG**      | -          | -        | -      | -     | -       | -       | yes     | -         | yes      | -       | -      | -       | -         |
| **StabilityAI**   | -          | -        | -      | yes   | yes     | yes     | yes     | -         | -        | -       | yes    | yes     | -         |
| **VertexAI**      | -          | -        | -      | -     | yes     | yes     | -       | -         | -        | -       | -      | yes     | yes       |
| **VoyageAI**      | -          | -        | -      | -     | -       | -       | -       | -         | -        | -       | -      | -       | yes       |

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
    ->ensure( 'imagine' )
    ->imagine( 'a grumpy cat' )
    ->binary();
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

The methods return a FileResponse or TextResponse object that contains the returned data
and optional meta and usage data.

**File data** is available by:

```php
$file = $response->binary(); // from binary, base64 and URL
$base64 = $response->base64(); // from binary, base64 and URL
$url = $response->url(); // only if URL is returned, otherwise NULL
```

URLs are automatically converted to binary and base64 data if requested and conversion between
binary and base64 data is done on request too.

**Meta data** is available by:

```php
$meta = $response->meta();
```

It returns an associative array whose content totally depends on the provider.

**Usage data** is available by:

```php
$usage = $response->usage();
```

It returns an associative array whose content depends on the provider. If the provider returns
usage information, the **used** array key is available and contains a number. What the number
represents depdends on the provider too.

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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->background( $image, 'Golden sunset on a caribbean beach' );
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
* [Ideogram](https://developer.ideogram.ai/api-reference/api-reference/describe#request)
* OpenAI

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->describe( $image, 'de' );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->detext( `$image` );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->erase( $image, $mask );
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

* [Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/model-parameters-titan-image.html)
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->imagine( 'Futuristic robot looking at a dashboard' );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->inpaint( $image, $mask, 'add a pink flamingo' );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->isolate( `$image` );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->recognize( `$image` );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->relocate( $image, $bgimage );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->repaint( $image, 'Use a van Goch style' );
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

* Clipdrop
* [StabilityAI](https://platform.stability.ai/docs/api-reference#tag/Edit/paths/~1v2beta~1stable-image~1edit~1outpaint/post)

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->uncrop( $image, 100, 200, 0, 50 );
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

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->upscale( $image, 4 );
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

$vectors = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->vectorize( $images, 512 )
    ->vectors();
```
