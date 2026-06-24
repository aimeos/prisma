## Custom providers

<nav>
<div class="method-header"><a href="#provider-discovery">Provider discovery</a></div>
<div class="method-header"><a href="#available-contracts">Available contracts</a></div>
<ul class="method-list">
    <li><a href="#audio">Audio</a></li>
    <li><a href="#image">Image</a></li>
    <li><a href="#text">Text</a></li>
    <li><a href="#video">Video</a></li>
</ul>
<div class="method-header"><a href="#new-provider-types">New provider types</a></div>
<div class="method-header"><a href="#base-skeleton">Base skeleton</a></div>
<div class="method-header"><a href="#configuration">Configuration</a></div>
<div class="method-header"><a href="#system-prompt">System prompt</a></div>
<div class="method-header"><a href="#token-limits">Token limits</a></div>
<div class="method-header"><a href="#file-types">File types</a></div>
<ul class="method-list">
    <li><a href="#factory-methods">Factory methods</a></li>
    <li><a href="#accessors">Accessors</a></li>
</ul>
<div class="method-header"><a href="#requests">Requests</a></div>
<div class="method-header"><a href="#error-handling">Error handling</a></div>
<ul class="method-list">
    <li><a href="#custom-error-handling">Custom error handling</a></li>
</ul>
<div class="method-header"><a href="#responses">Responses</a></div>
<ul class="method-list">
    <li><a href="#file-response">File response</a></li>
    <li><a href="#text-response">Text response</a></li>
    <li><a href="#vector-response">Vector response</a></li>
    <li><a href="#meta-data">Meta data</a></li>
    <li><a href="#finish-reason">Finish reason</a></li>
    <li><a href="#citations">Citations</a></li>
    <li><a href="#tool-steps">Tool steps</a></li>
</ul>
<div class="method-header"><a href="#rate-limits">Rate limits</a></div>
<div class="method-header"><a href="#structured-output">Structured output</a></div>
<ul class="method-list">
    <li><a href="#schema">Schema</a></li>
    <li><a href="#implementing-structure">Implementing structure()</a></li>
</ul>
<div class="method-header"><a href="#tool-support">Tool support</a></div>
<ul class="method-list">
    <li><a href="#available-methods">Available methods</a></li>
    <li><a href="#methods-to-implement">Methods to implement</a></li>
    <li><a href="#tool-loop-pattern">Tool loop pattern</a></li>
    <li><a href="#provider-tools">Provider tools</a></li>
</ul>
<div class="method-header"><a href="#async-operations">Async operations</a></div>
<div class="method-header"><a href="#openai-compatible-apis">OpenAI-compatible APIs</a></div>
<div class="method-header"><a href="#two-level-provider-pattern">Two-level provider pattern</a></div>
<div class="method-header"><a href="#examples">Examples</a></div>
<ul class="method-list">
    <li><a href="#audio--image--video-provider">Audio / Image / Video provider</a></li>
    <li><a href="#text-provider">Text provider</a></li>
</ul>
<div class="method-header"><a href="#testing">Testing</a></div>
<ul class="method-list">
    <li><a href="#makesprismarequests-trait">MakesPrismaRequests trait</a></li>
    <li><a href="#fake-provider">Fake provider</a></li>
</ul>
</nav>

### Provider discovery

Prisma resolves providers by convention: the class name must live in the
`Aimeos\Prisma\Providers\{Type}` namespace (e.g. `Text`, `Image`, `Audio`, `Video`).
The class name determines the provider name passed to `using()`:

```php
// Resolves to \Aimeos\Prisma\Providers\Text\Myprovider
$provider = Prisma::text()->using( 'myprovider', ['api_key' => '...'] );

// Resolves to \Aimeos\Prisma\Providers\Image\Myprovider
$provider = Prisma::image()->using( 'myprovider', ['api_key' => '...'] );
```

Check if a provider supports a specific method before calling it:

```php
if( Prisma::supports( 'text', 'myprovider', 'write', $config ) ) {
    // provider has a write() method
}
```

For testing, `Prisma::fake()` replaces all providers with a fake that returns
pre-built responses (see [Testing](#testing)):

```php
Prisma::fake( [TextResponse::fromText( 'Hello' )] );
```

### Available contracts

Each provider type has a set of contracts (interfaces) you can implement. A provider
only needs to implement the ones it supports.

#### Audio

| Contract | Method | Returns |
|----------|--------|---------|
| Demix | `demix( Audio $audio, int $stems, array $options = [] )` | FileResponse |
| Denoise | `denoise( Audio $audio, array $options = [] )` | FileResponse |
| Describe | `describe( Audio $audio, ?string $lang = null, array $options = [] )` | TextResponse |
| Revoice | `revoice( Audio $audio, string $voice, array $options = [] )` | FileResponse |
| Speak | `speak( string $text, ?string $voice = null, array $options = [] )` | FileResponse |
| Transcribe | `transcribe( Audio $audio, ?string $lang = null, array $options = [] )` | TextResponse |
| Vectorize | `vectorize( array $audio, ?int $size = null, array $options = [] )` | VectorResponse |

#### Image

| Contract | Method | Returns |
|----------|--------|---------|
| Background | `background( Image $image, string $prompt, array $options = [] )` | FileResponse |
| Describe | `describe( Image $image, ?string $lang = null, array $options = [] )` | TextResponse |
| Detext | `detext( Image $image, array $options = [] )` | FileResponse |
| Erase | `erase( Image $image, Image $mask, array $options = [] )` | FileResponse |
| Imagine | `imagine( string $prompt, array $images = [], array $options = [] )` | FileResponse |
| Inpaint | `inpaint( Image $image, Image $mask, string $prompt, array $options = [] )` | FileResponse |
| Isolate | `isolate( Image $image, array $options = [] )` | FileResponse |
| Recognize | `recognize( Image $image, array $options = [] )` | TextResponse |
| Relocate | `relocate( Image $image, Image $bgimage, array $options = [] )` | FileResponse |
| Repaint | `repaint( Image $image, string $prompt, array $options = [] )` | FileResponse |
| Uncrop | `uncrop( Image $image, int $top, int $right, int $bottom, int $left, array $options = [] )` | FileResponse |
| Upscale | `upscale( Image $image, int $factor, array $options = [] )` | FileResponse |
| Vectorize | `vectorize( array $images, ?int $size = null, array $options = [] )` | VectorResponse |

#### Text

| Contract | Method | Returns |
|----------|--------|---------|
| Structure | `structure( string $prompt, Schema $schema, array $files = [], array $options = [] )` | TextResponse |
| Translate | `translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] )` | TextResponse |
| Vectorize | `vectorize( array $texts, ?int $size = null, array $options = [] )` | VectorResponse |
| Write | `write( string $prompt, array $files = [], array $options = [] )` | TextResponse |

#### Video

| Contract | Method | Returns |
|----------|--------|---------|
| Describe | `describe( Video $video, ?string $lang = null, array $options = [] )` | TextResponse |

### New provider types

Prisma resolves provider types by namespace convention — no registration or
configuration is needed. `Prisma::type('embedding')->using('openai', $config)`
works as long as the class `Aimeos\Prisma\Providers\Embedding\Openai` exists and
is autoloaded via PSR-4.

To add a new provider type, follow these steps:

**1. Define capability contracts**

Create one interface per capability in `src/Contracts/{Type}/`:

```php
namespace Aimeos\Prisma\Contracts\Embedding;

use Aimeos\Prisma\Responses\VectorResponse;

interface Embed
{
    public function embed( array $texts, ?int $size = null, array $options = [] ) : VectorResponse;
}
```

**2. Choose a response class**

Use an existing response class if it fits (`TextResponse`, `FileResponse`,
`VectorResponse`). Only create a new one if the return data shape is genuinely
different from all existing responses.

**3. Create the provider**

```php
namespace Aimeos\Prisma\Providers\Embedding;

use Aimeos\Prisma\Contracts\Embedding\Embed;
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\VectorResponse;

class Openai extends Base implements Embed
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( 'https://api.openai.com' );
    }

    public function embed( array $texts, ?int $size = null, array $options = [] ) : VectorResponse
    {
        $options = $this->allowed( $options, ['model'] );

        $response = $this->client()->post( 'v1/embeddings', ['json' => [
            'model' => $options['model'] ?? 'text-embedding-3-small',
            'input' => $texts,
            'dimensions' => $size,
        ]] );

        $this->validate( $response );
        $data = $this->fromJson( $response );

        $vectors = array_map( fn( $item ) => $item['embedding'], $data['data'] ?? [] );

        return VectorResponse::fromVectors( $vectors )
            ->withUsage( $data['usage']['total_tokens'] ?? null, $data['usage'] ?? [] );
    }
}
```

If the API is OpenAI-compatible, the provider can extend a shared base class
(e.g. `Providers\Openai`) instead of `Base` directly — see [Two-level provider
pattern](#two-level-provider-pattern).

**4. Use the new type**

```php
use Aimeos\Prisma\Prisma;

$provider = Prisma::type( 'embedding' )->using( 'openai', ['api_key' => '...'] );
$response = $provider->embed( ['Hello world', 'Another text'], 256 );
```

Capability checks, `Prisma::supports()`, `Fake`, and `MakesPrismaRequests` all
work automatically for new types — no extra wiring is needed.

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

Implement one or more contracts for the chosen provider type:

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

### Configuration

The *config()* method safely extracts string values from the config array. It returns
the default value if the key is missing or not a string:

```php
protected function config( array $config, string $key, string $default = '' ) : string
```

Use it in constructors to support custom API URLs (for self-hosted or proxy setups):

```php
public function __construct( array $config )
{
    if( !isset( $config['api_key'] ) ) {
        throw new PrismaException( 'No API key' );
    }

    $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
    $this->baseUrl( $this->config( $config, 'url', 'https://api.default.com' ) );
}
```

### System prompt

The *systemPrompt()* method returns the system prompt set by the user via
`withSystemPrompt()`. Use it when building request payloads for text providers:

```php
$payload = [
    'model' => $this->modelName( 'default-model' ),
    'messages' => [['role' => 'user', 'content' => $prompt]],
];

if( $system = $this->systemPrompt() ) {
    $payload['system'] = $system;
}
```

### Token limits

The *maxTokens()* and *thinkingBudget()* methods return the values set by the
user via `withMaxTokens()` and `withThinkingBudget()`. Use them to configure
output length and extended thinking:

```php
$payload['max_tokens'] = $this->maxTokens() ?? 4096;

if( $budget = $this->thinkingBudget() ) {
    $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
}
```

### File types

The `Aimeos\Prisma\Files` namespace provides typed file classes. `File` is the
base class, while `Audio`, `Image` and `Video` extend it with mime type validation
(ensuring the mime type starts with `audio/`, `image/` or `video/` respectively).

#### Factory methods

All file classes support these static factory methods:

```php
use Aimeos\Prisma\Files\Image;

$file = Image::fromBinary( $binaryData, 'image/png' );
$file = Image::fromBase64( $base64Data, 'image/png' );
$file = Image::fromUrl( 'https://example.com/photo.jpg', 'image/jpeg' );
$file = Image::fromLocalPath( '/path/to/photo.jpg' );
$file = Image::fromStoragePath( 'photos/image.jpg', 'public', 'image/jpeg' );
```

The mime type parameter is optional but recommended. If omitted, it will be
guessed from the content when accessed.

#### Accessors

```php
$file->binary();    // raw binary content (fetches from URL if needed)
$file->base64();    // base64-encoded content
$file->url();       // original URL (if created from URL)
$file->mimeType();  // mime type string
$file->filename();  // filename (if available)
$file->as( 'name.png' );  // set the filename
```

### Requests

The *allowed()* and *sanitize()* methods filter options to only those the API
supports. This lets callers pass parameters for multiple providers at once:

```php
// filter key/value pairs in $options and use the ones allowed by the API
$allowed = $this->allowed( $options, ['<key1>', '<key2>', /* ... */] );

// filter values to pass only allowed option values (optional)
$allowed = $this->sanitize( $allowed, ['<key1>' => ['<val1>', '<val2>', '<val3>']])
```

The *modelName()* method returns the user's model choice or the given default:

```php
$model = $this->modelName( 'gemini-2.5-flash' );
```

The *payload()* method formats parameters and files for form or multipart
requests. Build JSON payloads directly:

```php
// Form data request
$data = $this->payload( $params );
// Multipart request
$data = ['multipart' => $this->payload( $params, ['image_key' => $image->binary()] )];
// JSON request
$data = ['json' => ['image_key' => array_map( fn( $image ) => $image->base64(), $images )] + $params];
```

Send the request via the Guzzle client, validate, and extract content:

```php
$response = $this->client()->post( 'relative/api/path', $data );
$this->validate( $response );
$content = $response->getBody()->getContents();
```

Full example:

```php
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\TextResponse;

public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
{
    $model = $this->modelName( 'flash' );
    $allowed = $this->allowed( $options, ['version'] );

    $params = ['language' => $lang] + $allowed;
    $data = ['multipart' => $this->payload( $params, ['file' => $image->binary()] )];
    $response = $this->client()->post( 'relative/api/path', $data );

    $this->validate( $response );

    $content = $response->getBody()->getContents();
    // return a response
}
```

### Error handling

The *validate()* method checks for HTTP 200, extracts the error message from
the JSON body (`error.message` or `message`), and calls *throw()* which maps
status codes to typed exceptions:

| HTTP Status | Exception |
|-------------|-----------|
| 400, 409, 422 | BadRequestException |
| 401 | UnauthorizedException |
| 402 | PaymentRequiredException |
| 403 | ForbiddenException |
| 404 | NotFoundException |
| 413 | SizeException |
| 429 | RateLimitException |
| 502, 503, 504 | OverloadedException |
| other | PrismaException |

All exceptions extend `PrismaException` (`Aimeos\Prisma\Exceptions` namespace).

The *fromJson()* method decodes a JSON response body into an array, throwing
`PrismaException` on invalid JSON.

```php
$data = $this->fromJson( $response );
```

#### Custom error handling

Override *validate()* when the API uses a different error format or status code
mapping:

```php
use Psr\Http\Message\ResponseInterface;

protected function validate( ResponseInterface $response ) : void
{
    if( $response->getStatusCode() === 200 ) {
        return;
    }

    $data = $this->fromJson( $response );
    $error = $data['detail'] ?? $response->getReasonPhrase();

    // remap status codes if needed (e.g. API returns 422 for auth errors)
    $this->throw( match( $response->getStatusCode() ) {
        422 => 400,
        default => $response->getStatusCode(),
    }, is_string( $error ) ? $error : '' );
}
```

### Responses

Three response types are available: `FileResponse`, `TextResponse` and
`VectorResponse`.

#### File response

A FileResponse contains one or more files as binary, base64 or URL. The mime
type is optional but prevents guessing later:

```php
use Aimeos\Prisma\Responses\FileResponse;

$response = FileResponse::fromBinary( '...', 'image/png' );
$response = FileResponse::fromBase64( '...', 'image/png' );
$response = FileResponse::fromUrl( '...', 'image/png' );
```

Add multiple files with *add()*:

```php
use Aimeos\Prisma\Files\File;

$response->add( File::fromBinary( '...', 'image/png' ) );
```

For asynchronous APIs, see the [Async operations](#async-operations) section.

#### Text response

```php
use Aimeos\Prisma\Responses\TextResponse;

$response = TextResponse::fromText( '...' );
$response->add( '...' ); // add more texts
```

Also supports *fromAsync()* — see [Async operations](#async-operations).

#### Vector response

A VectorResponse contains float vectors representing the input:

```php
use Aimeos\Prisma\Responses\VectorResponse;

$response = VectorResponse::fromVectors( [
    [0.27629, 0.89271, 0.98265, /* ... */],
    /* ... */
] );
```

#### Meta data

All responses support *withUsage()* and *withMeta()* for usage and meta data:

```php
$response->withUsage( // optional
    100, // used tokens, credits, etc. if available or NULL
    [] // arbitrary key/value pairs for the rest of the usage information
);
$response->withMeta( // optional
    [] // arbitrary meta data as key/value pairs, can be nested
);
```

TextResponse stores structured data from `structure()` or transcriptions:

```php
$response->withStructured( [
    // for transcriptions
    ['start' => 0.0, 'end' => 1.0, 'text' => 'This is a test.'],
    // ...
] );
```

Transcription entries must contain *start* (seconds), *end* (seconds) and *text*.
Additional key/value pairs are allowed.

FileResponse also supports *withDescription()*:

```php
$response->withDescription( '...' );
```

#### Finish reason

TextResponse supports *withReason()* to indicate why the model stopped
generating. Use the constants defined in the `HasReason` trait:

| Constant | Meaning |
|----------|---------|
| `STOP` | Model finished normally |
| `LENGTH` | Hit max token limit |
| `TOOL` | Stopped for tool calls (maxSteps exhausted) |
| `CONTENT` | Blocked by safety/content filter |
| `ERROR` | Provider returned an error |
| `UNKNOWN` | Unrecognized finish reason |

```php
return TextResponse::fromText( $data['text'] ?? '' )
    ->withReason( match( $data['finish_reason'] ?? '' ) {
        'stop' => self::STOP,
        'length' => self::LENGTH,
        'tool_calls' => self::TOOL,
        'content_filter' => self::CONTENT,
        default => self::UNKNOWN,
    } );
```

#### Citations

TextResponse supports *withCitations()* for source references. Each `Citation`
has optional `title`, `url`, `text` (cited output) and `source` (verbatim quote):

```php
use Aimeos\Prisma\Values\Citation;

$citations = [];

foreach( $data['citations'] ?? [] as $cit ) {
    $citations[] = new Citation(
        title: $cit['title'] ?? null,
        url: $cit['url'] ?? null,
        text: $cit['cited_text'] ?? null,
        source: $cit['source_text'] ?? null,
    );
}

return TextResponse::fromText( $data['text'] ?? '' )
    ->withCitations( $citations );
```

#### Tool steps

TextResponse supports *withSteps()* to record tool call history. Each `Step`
contains the tool call ID, name, arguments and result:

```php
use Aimeos\Prisma\Tools\Step;

// after executing tools with execTools()
return TextResponse::fromText( $data['text'] ?? '' )
    ->withSteps( $allSteps );
```

Callers can inspect tool steps via `$response->steps()`, where each `Step`
provides `id()`, `name()`, `arguments()` and `result()`.

### Rate limits

The *getRateLimit()* method extracts rate limit info from standard HTTP headers
(`x-ratelimit-limit`, `x-ratelimit-remaining`, `x-ratelimit-reset`, `retry-after`).
Attach it to any response with *withRateLimit()*:

```php
$response = $this->client()->post( 'v1/chat', $data );
$this->validate( $response );
$data = $this->fromJson( $response );

return TextResponse::fromText( $data['text'] ?? '' )
    ->withRateLimit( $this->getRateLimit( $response ) );
```

The `RateLimit` value object provides these accessors:

```php
$rateLimit = $response->rateLimit();
$rateLimit->limit();       // request limit (int or null)
$rateLimit->remaining();   // remaining requests (int or null)
$rateLimit->reset();       // reset timestamp (string or null)
$rateLimit->retryAfter();  // retry after seconds (int or null)
```

### Structured output

Text providers can implement the `Structure` contract to return data matching
a defined schema.

#### Schema

The `Schema` class defines the expected JSON output structure using a fluent API:

```php
use Aimeos\Prisma\Schema\Schema;

$schema = Schema::for( 'person', [
    'name' => Schema::string()->description( 'Full name' )->required(),
    'age' => Schema::integer()->description( 'Age in years' ),
    'tags' => Schema::array()->items( Schema::string() ),
    'address' => Schema::object( [
        'city' => Schema::string()->required(),
        'zip' => Schema::string(),
    ] ),
] );
```

You can also create a schema from an existing JSON Schema array:

```php
$schema = Schema::fromArray( 'person', $jsonSchemaArray );
```

Available type builders:

| Type | Builder | Type-specific methods |
|------|---------|---------------------|
| string | `Schema::string()` | `min()`, `max()`, `pattern()`, `format()`, `default()` |
| integer | `Schema::integer()` | `min()`, `max()`, `default()` |
| number | `Schema::number()` | `min()`, `max()`, `default()` |
| boolean | `Schema::boolean()` | `default()` |
| array | `Schema::array()` | `items()`, `min()`, `max()`, `unique()`, `default()` |
| object | `Schema::object()` | `withoutAdditionalProperties()`, `default()` |

All types share these methods: `description()`, `title()`, `required()`, `nullable()`, `enum()`.

The `enum()` method accepts either an array of values or a `BackedEnum` class name:

```php
Schema::string()->enum( ['draft', 'published', 'archived'] )
Schema::string()->enum( StatusEnum::class )
```

Schema instance methods:

```php
$schema->toArray();    // full JSON Schema array
$schema->toString();   // JSON string
$schema->filter( ['type', 'description', 'properties', 'required', 'items'] );  // filtered schema
$schema->name();       // schema name
$schema->strict();     // enable strict mode
$schema->isStrict();   // check strict mode
```

The *filter()* method keeps only specified keys (recursively). This is useful for
APIs that reject unknown JSON Schema fields.

#### Implementing structure()

For APIs with native structured output support:

```php
use Aimeos\Prisma\Schema\Schema;
use Aimeos\Prisma\Responses\TextResponse;

public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
{
    $allowed = $this->allowed( $options, ['temperature'] );
    $model = $this->modelName( 'default-model' );

    $payload = [
        'model' => $model,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schema->name(),
                'schema' => $schema->toArray(),
                'strict' => $schema->isStrict(),
            ],
        ],
    ] + $allowed;

    $response = $this->client()->post( 'v1/chat/completions', ['json' => $payload] );
    $this->validate( $response );
    $data = $this->fromJson( $response );

    $text = $data['choices'][0]['message']['content'] ?? '';
    $structured = json_decode( $text, true ) ?: [];

    return TextResponse::fromText( $text )->withStructured( $structured );
}
```

For APIs without native support, use prompt engineering as a fallback:

```php
$schemaPrompt = $prompt
    . "\n\nRespond with ONLY valid JSON matching this schema:\n"
    . $schema->toString();

// ... send $schemaPrompt to the API ...

$text = trim( $data['text'] ?? '' );
$text = preg_replace( '/^```(?:json)?\s*|\s*```$/s', '', $text ) ?? $text;
$structured = json_decode( $text, true ) ?: [];

return TextResponse::fromText( $text )->withStructured( $structured );
```

### Tool support

Text providers that support tool calling need the `CallsTools` trait in their
mid-level base class.

#### Available methods

| Method | Purpose |
|--------|---------|
| `execTools( array $toolCalls )` | Execute tool calls, returns array of `Step` results |
| `tools()` | Returns user-provided tool adapters |
| `providerTools()` | Returns built-in provider tool adapters |
| `toolChoice()` | Returns tool choice setting (`self::AUTO`, `self::REQUIRED`, `self::NONE`) |
| `maxSteps()` | Returns max tool loop iterations |
| `concurrency()` | Returns concurrency strategy for parallel tool execution |

#### Methods to implement

Each provider must implement these three methods to match its API format:

```php
// Format tools array for the API
protected function toolsParam() : array
{
    $tools = [];

    foreach( $this->tools() as $tool ) {
        $tools[] = [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->schema()->toArray(),
        ];
    }

    return $tools;
}


// Extract tool calls from API response
protected function toolCalls( array $result ) : array
{
    $toolCalls = [];

    foreach( $result['tool_calls'] ?? [] as $call ) {
        $toolCalls[] = [
            'id' => $call['id'] ?? null,
            'name' => $call['function']['name'] ?? '',
            'arguments' => json_decode( $call['function']['arguments'] ?? '{}', true ) ?: [],
        ];
    }

    return $toolCalls;
}


// Format tool results as messages for the next API call
protected function toolResults( array $results ) : array
{
    $messages = [];

    foreach( $results as $step ) {
        $messages[] = [
            'role' => 'tool',
            'tool_call_id' => $step->id(),
            'content' => $step->result(),
        ];
    }

    return $messages;
}
```

#### Tool loop pattern

The tool loop sends the request, checks for tool calls, executes them, appends
results, and repeats until no more tool calls or `maxSteps()` is reached:

```php
private function generate( array $messages, array $options ) : TextResponse
{
    $allSteps = [];

    for( $step = 1; $step <= $this->maxSteps(); $step++ )
    {
        $params = [
            'model' => $this->modelName( 'default-model' ),
            'messages' => $messages,
        ] + $options;

        if( $tools = $this->toolsParam() ) {
            $params['tools'] = $tools;
            $params['tool_choice'] = $this->toolChoice();
        }

        $response = $this->client()->post( 'v1/chat/completions', ['json' => $params] );
        $this->validate( $response );
        $result = $this->fromJson( $response );

        $toolCalls = $this->toolCalls( $result );

        if( !$toolCalls ) {
            break;
        }

        $toolResults = $this->execTools( $toolCalls );
        array_push( $allSteps, ...$toolResults );

        $messages[] = $result['choices'][0]['message'] ?? [];
        $messages = array_merge( $messages, $this->toolResults( $toolResults ) );
    }

    $text = $result['choices'][0]['message']['content'] ?? '';

    return TextResponse::fromText( $text )
        ->withSteps( $allSteps )
        ->withReason( $toolCalls ? self::TOOL : self::STOP );
}
```

#### Provider tools

Map built-in provider tools (web search, code execution) using
`mapProviderTools()` with a static map:

```php
private static array $providerToolMap = [
    'web_search' => [
        'type' => 'web_search',
        'options' => ['allowed_domains', 'search_context_size'],
    ],
    'code_execution' => [
        'type' => 'code_interpreter',
    ],
];


protected function toolsParam() : array
{
    $tools = [];

    foreach( $this->tools() as $tool ) {
        $tools[] = [
            'name' => $tool->name(),
            'description' => $tool->description(),
            'parameters' => $tool->schema()->toArray(),
        ];
    }

    return array_merge( $tools, $this->mapProviderTools( self::$providerToolMap ) );
}
```

### Async operations

For APIs that require polling, both FileResponse and TextResponse support
*fromAsync()*:

```php
FileResponse::fromAsync( Closure $closure, int $retry = 5 ) : FileResponse
TextResponse::fromAsync( Closure $closure, int $retry = 5 ) : TextResponse
```

The closure receives the response object and returns `true` when ready or
`false` to keep polling. `$retry` is the sleep interval in seconds.

Typical pattern — a separate method returning the polling closure:

```php
use Aimeos\Prisma\Files\Image;
use Aimeos\Prisma\Responses\FileResponse;

public function imagine( string $prompt, array $images = [], array $options = [] ) : FileResponse
{
    $response = $this->client()->post( 'v1/generate', ['json' => ['prompt' => $prompt]] );
    $this->validate( $response );
    $data = $this->fromJson( $response );

    return FileResponse::fromAsync( $this->download( $data['polling_url'] ?? '' ), 2 );
}


protected function download( string $url ) : \Closure
{
    $client = $this->client();

    return function( FileResponse $fr ) use ( $client, $url ) : bool {
        $response = $client->get( $url );
        $data = json_decode( $response->getBody()->getContents(), true ) ?: [];

        if( ( $data['status'] ?? '' ) !== 'Ready' ) {
            return false;
        }

        $fr->add( Image::fromUrl( $data['url'] ?? '' ) );
        return true;
    };
}
```

The same pattern works for TextResponse:

```php
use Aimeos\Prisma\Responses\TextResponse;

return TextResponse::fromAsync( function( TextResponse $tr ) use ( $client, $id ) : bool {
    $response = $client->get( "v1/jobs/{$id}" );
    $data = json_decode( $response->getBody()->getContents(), true ) ?: [];

    if( ( $data['status'] ?? '' ) !== 'COMPLETED' ) {
        return false;
    }

    $tr->add( $data['text'] ?? '' );
    return true;
}, 3 );
```

Use `$response->ready()` for non-blocking checks. Accessing content (`files()`,
`text()`) blocks until the operation completes.

### OpenAI-compatible APIs

The `OpenaiApi` trait provides ready-made methods for OpenAI-compatible APIs,
handling the full request/response cycle including tool loops:

| Method | Purpose |
|--------|---------|
| `completions()` | Chat completions with tool loop |
| `responses()` | OpenAI Responses API with tool loop |
| `structuredCompletions()` | Completions with JSON schema response format |
| `structuredResponses()` | Responses API with JSON schema format |
| `content()` | Build content blocks from prompt and files |
| `messages()` | Build messages array with optional system prompt |
| `toolsParam()` | Format tools in OpenAI function calling format |
| `toolCalls()` | Extract tool calls from completions response |

Use both `CallsTools` and `OpenaiApi` traits in the mid-level base:

```php
<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Concerns\CallsTools;
use Aimeos\Prisma\Concerns\OpenaiApi;
use Aimeos\Prisma\Exceptions\PrismaException;


class Myprovider extends Base
{
    use CallsTools;
    use OpenaiApi;

    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.myprovider.com' ) );
    }
}
```

The text provider becomes minimal:

```php
<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Structure;
use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Myprovider as Base;
use Aimeos\Prisma\Responses\TextResponse;
use Aimeos\Prisma\Schema\Schema;


class Myprovider extends Base implements Structure, Write
{
    public function structure( string $prompt, Schema $schema, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p'] );

        return $this->structuredCompletions(
            'v1/chat/completions', 'default-model',
            $this->messages( $this->content( $prompt, $files ) ),
            $schema, $options
        );
    }


    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $options = $this->allowed( $options, ['temperature', 'top_p'] );

        return $this->completions(
            'v1/chat/completions', 'default-model',
            $this->messages( $this->content( $prompt, $files ) ),
            $options
        );
    }
}
```

### Two-level provider pattern

When a provider serves multiple types and needs shared helpers, use a
two-level pattern instead of extending `Base` directly:

```
Providers/Base.php              (framework base, all providers)
    Providers/Myprovider.php        (shared: auth, helpers)
        Providers/Text/Myprovider.php   (implements Write, Structure)
        Providers/Audio/Myprovider.php  (implements Speak, Transcribe)
```

Mid-level base with shared authentication and helpers:

```php
<?php

namespace Aimeos\Prisma\Providers;

use Aimeos\Prisma\Exceptions\PrismaException;


class Myprovider extends Base
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $this->config( $config, 'api_key' ) );
        $this->baseUrl( $this->config( $config, 'url', 'https://api.myprovider.com' ) );
    }


    protected function content( string $prompt, array $files ) : array
    {
        // shared logic for building content blocks from prompt and files
        $parts = [['type' => 'text', 'text' => $prompt]];

        foreach( $files as $file ) {
            $parts[] = ['type' => 'image', 'data' => $file->base64()];
        }

        return $parts;
    }
}
```

Type-specific subclass:

```php
<?php

namespace Aimeos\Prisma\Providers\Text;

use Aimeos\Prisma\Contracts\Text\Write;
use Aimeos\Prisma\Providers\Myprovider as Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Write
{
    public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, ['temperature', 'top_p'] );

        $payload = [
            'model' => $this->modelName( 'default-model' ),
            'messages' => [['role' => 'user', 'content' => $this->content( $prompt, $files )]],
        ] + $allowed;

        if( $system = $this->systemPrompt() ) {
            $payload['system'] = $system;
        }

        $response = $this->client()->post( 'v1/chat', ['json' => $payload] );
        $this->validate( $response );
        $data = $this->fromJson( $response );

        return TextResponse::fromText( $data['choices'][0]['message']['content'] ?? '' )
            ->withUsage( $data['usage']['total_tokens'] ?? null, $data['usage'] ?? [] )
            ->withRateLimit( $this->getRateLimit( $response ) );
    }
}
```

### Examples

#### Audio / Image / Video provider

Audio, Image and Video providers follow the same pattern — only the namespace,
contract, file class and method signature differ:

```php
<?php

namespace Aimeos\Prisma\Providers\Image; // or Audio, Video

use Aimeos\Prisma\Contracts\Image\Describe; // or Audio\Describe, Video\Describe
use Aimeos\Prisma\Exceptions\PrismaException;
use Aimeos\Prisma\Files\Image; // or Audio, Video
use Aimeos\Prisma\Providers\Base;
use Aimeos\Prisma\Responses\TextResponse;


class Myprovider extends Base implements Describe
{
    public function __construct( array $config )
    {
        if( !isset( $config['api_key'] ) ) {
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'x-api-key', $config['api_key'] );
        $this->baseUrl( 'https://ai.com' );
    }


    public function describe( Image $image, ?string $lang = null, array $options = [] ) : TextResponse
    {
        $allowed = $this->allowed( $options, ['version'] );
        $model = $this->modelName( 'flash' );

        $params = ['language' => $lang, 'model' => $model] + $allowed;
        $data = ['multipart' => $this->payload( $params, ['file' => $image->binary()] )];
        $response = $this->client()->post( 'relative/api/path', $data );

        $this->validate( $response );

        $data = $this->fromJson( $response );

        return TextResponse::fromText( $data['text'] ?? '' )
            ->withStructured( $data['segments'] ?? [] )
            ->withUsage( $data['usage']['total'] ?? null, $data['usage'] ?? [] )
            ->withMeta( $data['meta'] ?? [] );
    }
}
```

#### Text provider

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
            throw new PrismaException( 'No API key' );
        }

        $this->header( 'Authorization', 'Bearer ' . $config['api_key'] );
        $this->baseUrl( 'https://ai.com' );
    }


    public function translate( array $texts, string $to, ?string $from = null, ?string $context = null, array $options = [] ) : TextResponse
    {
        $payload = [
            'texts' => $texts,
            'target_lang' => $to,
            'source_lang' => $from,
        ] + $this->allowed( $options, ['formality'] );

        $response = $this->client()->post( '/v1/translate', ['json' => $payload] );
        $this->validate( $response );

        $data = $this->fromJson( $response );
        $translated = array_map( fn( $item ) => $item['text'] ?? '', $data ?? [] );

        return TextResponse::fromTexts( $translated )
            ->withUsage( $data['usage']['total'] ?? 0, $data['usage'] ?? [] )
            ->withMeta( $data['meta'] ?? [] );
    }
}
```

### Testing

#### MakesPrismaRequests trait

The `MakesPrismaRequests` trait provides a mocked HTTP layer for PHPUnit:

| Method | Purpose |
|--------|---------|
| `prisma( string $type, string $name, array $config )` | Set up a provider with mocked HTTP |
| `response( string\|array $body, array $headers, int $status, string $reason )` | Queue a fake HTTP response, returns Provider |
| `requests()` | Get all recorded HTTP requests |
| `provider()` | Access the underlying provider instance |
| `assertPrismaRequest( callable $callback )` | Assert a matching request was sent |

```php
use Tests\MakesPrismaRequests;
use PHPUnit\Framework\TestCase;

class MyproviderTest extends TestCase
{
    use MakesPrismaRequests;

    public function testDescribe() : void
    {
        $this->prisma( 'image', 'myprovider', ['api_key' => 'test'] );

        $result = $this->response( ['text' => 'A photo of a cat'] )
            ->describe( Image::fromUrl( 'https://example.com/cat.jpg' ) );

        $this->assertEquals( 'A photo of a cat', $result->text() );

        $this->assertPrismaRequest( function( $request, $options ) {
            return str_contains( $request->getUri()->getPath(), 'relative/api/path' );
        } );
    }
}
```

Queue multiple responses for tool loops or async polling:

```php
$this->prisma( 'text', 'myprovider', ['api_key' => 'test'] );

$this->response( ['status' => 'pending'] );
$result = $this->response( ['text' => 'Done'] )
    ->write( 'Hello' );
```

#### Fake provider

The `Fake` provider returns pre-built responses without HTTP, validating
method names against a real provider:

```php
use Aimeos\Prisma\Providers\Fake;
use Aimeos\Prisma\Responses\TextResponse;

$fake = new Fake( [
    TextResponse::fromText( 'Hello' ),
    TextResponse::fromText( 'World' ),
] );

$fake->use( new \Aimeos\Prisma\Providers\Text\Myprovider( ['api_key' => 'test'] ) );

$result = $fake->write( 'prompt' );  // returns "Hello"
$result = $fake->write( 'prompt' );  // returns "World"
```
