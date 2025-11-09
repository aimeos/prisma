# PHP Prisma

A powerful PHP package for integrating media related Large Language Models (LLMs) into your applications using a unified interface.

## Supported providers

### Image

|            | Clipdrop | Gemini  | Ideogram |  OpenAI | RemoveBG | StabilityAI |
| :---       |   :---:  |  :---:  |  :---:   |  :---:  |  :---:   |  :---:      |
| Background |     +    |    -    |    +     |    -    |    -     |    -        |
| Describe   |     -    |    +    |    +     |    +    |    -     |    -        |
| Detext     |     +    |    -    |    -     |    -    |    -     |    -        |
| Erase      |     +    |    -    |    -     |    -    |    -     |    +        |
| Imagine    |     +    |    +    |    +     |    +    |    -     |    +        |
| Inpaint    |     -    |    -    |    +     |    +    |    -     |    +        |
| Isolate    |     +    |    -    |    -     |    -    |    +     |    +        |
| Relocate   |     -    |    -    |    -     |    -    |    +     |    -        |
| Repaint    |     -    |    +    |    +     |    -    |    -     |    -        |
| Studio     |     +    |    -    |    -     |    -    |    +     |    -        |
| Uncrop     |     +    |    -    |    -     |    -    |    -     |    +        |
| Upscale    |     +    |    -    |    +     |    -    |    -     |    +        |

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

## Table of contents

1. [Common API](#common-api)
    * [ensure](#ensure)
    * [has](#has)
    * [model](#model)
    * [withClientOptions](#withClientOptions)
    * [withSystemPrompt](#withSystemPrompt)
2. [Image API](#image-api)
    * [background](#background)
    * [describe](#describe)
    * [detext](#detext)
    * [erase](#erase)
    * [imagine](#imagine)
    * [inpaint](#inpaint)
    * [isolate](#isolate)
    * [relocate](#relocate)
    * [repaint](#repaint)
    * [studio](#studio)
    * [uncrop](#uncrop)
    * [upscale](#upscale)

## Common API

### ensure

Ensures that the provider has implemented the method.

```php
public function ensure( string $method ) : self
```

* @param string $method Method name
* @return Provider
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

* @param string $method Method name
* @return bool TRUE if implemented, FALSE if absent

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

* @param string|null $model Model name
* @return self Provider interface

**Example:**

```php
\Aimeos\Prisma\Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->model( 'dall-e-3' );
```

### withClientOptions

Add options for the Guzzle HTTP client.

```php
public function withClientOptions( array $options ) : self
```

* @param array<string, mixed> $options Associative list of name/value pairs
* @return self Provider interface

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

* @param string|null $prompt System prompt
* @return self Provider interface

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

All methods return a FileResponse object (besides *describe()*, which returns a TextResponse
object) that contains the file data and optional meta and usage data.

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

## background

Replace image background with a background described by the prompt.

```php
public function background( Image $image, string $prompt, array $options = [] ) : FileResponse
```

* @param Image $image Input image object
* @param string $prompt Prompt describing the new background
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

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

* @param Image $image Input image object
* @param string|null $lang ISO language code the description should be generated in
* @param array<string, mixed> $options Provider specific options
* @return TextResponse Response text

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

* @param Image $image Input image object
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->detext( $image );
```

### erase

Erase parts of the image.

```php
public function erase( Image $image, Image $mask, array $options = [] ) : FileResponse
```

* @param Image $image Input image object
* @param Image $mask Mask image object
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

The mask must be an image with black parts (#000000) to keep and white parts (#FFFFFF)
to remove.

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

* @param string $prompt Prompt describing the image
* @param array<int, \Aimeos\Prisma\Files\Image> $images Associative list of file name/Image instances
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

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

* @param Image $image Input image object
* @param Image $mask Input mask image object
* @param string $prompt Prompt describing the changes
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

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

* @param Image $image Input image object
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->isolate( $image );
```

### relocate

* Place the foreground object on a new background.

```php
public function relocate( Image $image, Image $bgimage, array $options = [] ) : FileResponse
```

* @param Image $image Input image with foreground object
* @param Image $bgimage Background image
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

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

* @param Image $image Input image object
* @param string $prompt Prompt describing the changes
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->repaint( $image, 'Use a van Goch style' );
```

### studio

Create studio photo from the object in the foreground of the image.

```php
public function studio( Image $image, array $options = [] ) : FileResponse
```

* @param Image $image Input image object
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->studio( $image );
```

### uncrop

Extend/outpaint the image.

```php
public function uncrop( Image $image,  int $top, int $right, int $bottom, int $left, array $options = [] ) : FileResponse
```

* @param Image $image Input image object
* @param int $top Number of pixels to extend to the top
* @param int $right Number of pixels to extend to the right
* @param int $bottom Number of pixels to extend to the bottom
* @param int $left Number of pixels to extend to the left
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

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
public function upscale( Image $image, int $width, int $height, array $options = [] ) : FileResponse
```

* @param Image $image Input image object
* @param int $width Width of the upscaled image in pixels
* @param int $height Height of the upscaled image in pixels
* @param array<string, mixed> $options Provider specific options
* @return FileResponse Response file

**Example:**

```php
use Aimeos\Prisma\Prisma;
use \Aimeos\Prisma\Files\Image;

$image = Image::fromUrl( 'https://example.com/image.png' );

$response = Prisma::image()
    ->using( '<provider>', ['api_key' => 'xxx'])
    ->upscale( $image, 1920, 1080 );
```
