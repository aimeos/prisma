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
    <li><a href="#withMaxTokens">withMaxTokens</a><span>: Set the maximum number of output tokens</span></li>
    <li><a href="#withThinkingBudget">withThinkingBudget</a><span>: Set the thinking/reasoning budget in tokens</span></li>
    <li><a href="#response-objects">Response objects</a><span>: How data is returned by the API</span></li>
    <li><a href="#finish-reason">Finish reason</a><span>: Why generation stopped</span></li>
    <li><a href="#tool-steps">Tool steps</a><span>: Inspect tool call history</span></li>
    <li><a href="#rate-limit">Rate limit</a><span>: Rate limit information from providers</span></li>
</ul>
<div class="method-header"><a href="#schemas">Schemas</a></div>
<ul class="method-list">
    <li><a href="#building-schemas">Building schemas</a><span>: Define tool parameters using the fluent Schema builder</span></li>
    <li><a href="#from-arrays">From arrays</a><span>: Create schemas from JSON Schema arrays</span></li>
    <li><a href="#type-reference">Type reference</a><span>: Available types and their methods</span></li>
</ul>
<div class="method-header"><a href="#tools">Tools</a></div>
<ul class="method-list">
    <li><a href="#creating-tools">Creating tools</a><span>: Create tools using the Tools facade</span></li>
    <li><a href="#provider-tools">Provider tools</a><span>: Built-in tools executed server-side</span></li>
    <li><a href="#tool-state">Tool state</a><span>: Check a tool's remaining call budget</span></li>
    <li><a href="#error-handling">Error handling</a><span>: Customize how tool errors are returned</span></li>
    <li><a href="#concurrent-tools">Concurrent tools</a><span>: Run tools in parallel</span></li>
    <li><a href="#decorating-tools">Decorating tools</a><span>: Wrap tools with additional behavior</span></li>
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
    <li><a href="#write">write</a><span>: Generate text from the given prompt</span></li>
</ul>
<div class="method-header"><a href="#video-api">Video API</a></div>
<ul class="method-list">
    <li><a href="#describe">describe</a><span>: Describe the content of a video</span></li>
</ul>
<div class="method-header"><a href="#custom-providers">Custom providers</a></div>
<ul class="method-list">
    <li><a href="#base-skeleton">Base skeleton</a><span>: Basic structure for a custom provider</span></li>
    <li><a href="#requests">Requests</a><span>: Support methods for building HTTP requests</span></li>
    <li><a href="#responses">Responses</a><span>: Available response types and their usage</span></li>
    <li><a href="#examples">Examples</a><span>: Full provider implementation examples</span></li>
</ul>
</nav>

## Supported providers

- [Alibaba](https://www.alibabacloud.com/help/en/model-studio/model-api-reference/)
- [Anthropic](https://docs.anthropic.com/en/api)
- [AudioPod AI](https://audiopod.ai/)
- [Bedrock Titan (AWS)](https://docs.aws.amazon.com/bedrock/latest/userguide/titan-models.html)
- [Black Forest Labs](https://docs.bfl.ai/quick_start/introduction)
- [Clipdrop](https://clipdrop.co/apis)
- [Cohere](https://docs.cohere.com/docs/the-cohere-platform)
- [DeepL](https://developers.deepl.com/docs)
- [Deepgram](https://deepgram.com/)
- [Deepseek](https://api-docs.deepseek.com/)
- [ElevenLabs](https://elevenlabs.io/docs/overview/intro)
- [Gemini (Google)](https://aistudio.google.com/models/gemini-2-5-flash-image)
- [Google Translate](https://cloud.google.com/translate/docs/reference/rest/v2/translate)
- [Groq](https://groq.com/)
- [Ideogram](https://ideogram.ai/api)
- [Mistral](https://docs.mistral.ai/api)
- [Murf](https://murf.ai/api)
- [OpenAI](https://openai.com/api/)
- [Openrouter](https://openrouter.ai/docs/quickstart)
- [Perplexity](https://docs.perplexity.ai/)
- [RemoveBG](https://www.remove.bg/api)
- [StabilityAI](https://platform.stability.ai/)
- [VertexAI (Google)](https://cloud.google.com/vertex-ai/generative-ai/docs)
- [VoyageAI](https://docs.voyageai.com/)
- [xAI](https://docs.x.ai/)

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

|                       | translate | write | custom tools | provider tools |
| :---                  | :---:     | :---: | :---:        | :---:          |
| **Alibaba**           |           | yes   | yes          | yes            |
| **Anthropic**         |           | yes   | yes          | yes            |
| **Bedrock**           |           | yes   | yes          |                |
| **Cohere**            |           | yes   | yes          |                |
| **Deepseek**          |           | yes   | yes          |                |
| **DeepL**             | yes       |       |              |                |
| **Gemini**            |           | yes   | yes          | yes            |
| **Google**            | yes       |       |              |                |
| **Groq**              |           | yes   | yes          |                |
| **Mistral**           |           | yes   | yes          | yes            |
| **OpenAI**            |           | yes   | yes          | yes            |
| **Openrouter**        |           | yes   | yes          | yes            |
| **Perplexity**        |           | beta  | yes          |                |
| **xAI**               |           | beta  | yes          | yes            |

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

### withMaxTokens

Set the maximum number of output tokens for the response.

```php
public function withMaxTokens( ?int $tokens ) : self
```

* @param **int&#124;null** `$tokens` Maximum output tokens
* @return **self** Provider interface

**Example:**

```php
\Aimeos\Prisma\Prisma::text()
    ->using( '<provider>', ['api_key' => 'xxx'] )
    ->withMaxTokens( 4096 )
    ->write( 'Tell me a story' );
```

### withThinkingBudget

Set the thinking/reasoning budget in tokens for models that support extended
thinking. The budget is mapped to each provider's native format automatically:
token counts for Anthropic, OpenAI, Gemini and Bedrock; effort levels for other
OpenAI-API providers (&#8804; 1024 → low, &#8804; 8192 → medium, > 8192 → high).

```php
public function withThinkingBudget( ?int $budget ) : self
```

* @param **int&#124;null** `$budget` Thinking budget in tokens
* @return **self** Provider interface

**Example:**

```php
$response = \Aimeos\Prisma\Prisma::text()
    ->using( '<provider>', ['api_key' => 'xxx'] )
    ->withThinkingBudget( 5000 )
    ->withMaxTokens( 4096 )
    ->write( 'Solve this step by step' );

// Access the model's reasoning (if returned by the provider)
$thinking = $response->meta()['thinking'] ?? null;
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

### Finish reason

TextResponse objects include a finish reason indicating why the model stopped generating:

```php
$response = Prisma::text()
    ->using( 'openai', ['api_key' => 'xxx'] )
    ->withTools( [$tool] )
    ->withMaxSteps( 5 )
    ->write( 'What is the weather in Berlin?' );

$reason = $response->reason(); // 'stop', 'tool', 'length', 'content', 'error', or 'unknown'
```

| Reason | Meaning |
| :--- | :--- |
| `stop` | The model finished normally (reached a natural end or stop sequence) |
| `tool` | The model stopped to request tool calls; returned when `withMaxSteps()` is exhausted mid-loop |
| `length` | Output was truncated because it hit the max token limit |
| `content` | Output was blocked or truncated by a safety/content filter |
| `error` | The provider returned an error during generation |
| `unknown` | The provider returned an unrecognized finish reason |

### Tool steps

After a tool-using request completes, inspect the full history of tool calls and their results via `steps()`:

```php
$response = Prisma::text()
    ->using( 'openai', ['api_key' => 'xxx'] )
    ->withTools( [$tool] )
    ->withMaxSteps( 5 )
    ->write( 'What is the weather in Berlin?' );

foreach( $response->steps() as $step ) {
    $step->id();        // tool call ID from the provider
    $step->name();      // tool name (e.g. 'weather')
    $step->arguments(); // arguments the model passed (e.g. ['city' => 'Berlin'])
    $step->result();    // result string returned to the model
}
```

### Rate limit

TextResponse and FileResponse objects can include rate limit information from the provider:

```php
$rateLimit = $response->rateLimit();
// e.g. ['limit' => 1000, 'remaining' => 999, 'reset' => 1620000000]
```

The content of the returned array depends on the provider. It may be empty if the provider does not return rate limit headers.

## Schemas

Schemas define the parameters that tools accept. They are used by `Tools::make()` to tell the LLM what arguments a tool expects.

### Building schemas

Use the fluent `Schema` builder to define tool parameters:

```php
use Aimeos\Prisma\Schema\Schema;

$schema = Schema::for( 'search', [
    'query' => Schema::string()->description( 'Search query' )->required(),
    'limit' => Schema::integer()->description( 'Max results' )->min( 1 )->max( 100 ),
] );
```

`Schema::for()` creates a named schema with an object type. The first argument is the schema name, the second is an associative array of property names to types.

**Nested objects:**

```php
$schema = Schema::for( 'create_event', [
    'title' => Schema::string()->required(),
    'location' => Schema::object( [
        'city' => Schema::string()->required(),
        'country' => Schema::string(),
    ] )->required(),
] );
```

**Arrays:**

```php
$schema = Schema::for( 'tag', [
    'tags' => Schema::array()->items( Schema::string() )->min( 1 )->max( 10 )->required(),
    'scores' => Schema::array()->items( Schema::number() ),
] );
```

**Enums:**

```php
$schema = Schema::for( 'sort', [
    'order' => Schema::string()->enum( ['asc', 'desc'] )->required(),
] );

// Or from a BackedEnum:
$schema = Schema::for( 'sort', [
    'order' => Schema::string()->enum( SortOrder::class )->required(),
] );
```

**Strict mode** (for providers that support it, e.g. OpenAI):

```php
$schema = Schema::for( 'search', [
    'query' => Schema::string()->required(),
] )->strict();
```

### From arrays

If you already have a JSON Schema array, use `Schema::fromArray()`:

```php
$schema = Schema::fromArray( 'search', [
    'type' => 'object',
    'properties' => [
        'query' => ['type' => 'string', 'description' => 'Search query'],
        'limit' => ['type' => 'integer'],
    ],
    'required' => ['query'],
] );
```

### Type reference

All types support these common methods: `description()`, `required()`, `nullable()`, `title()`, `enum()`.

| Factory method | Type | Additional methods |
| :--- | :--- | :--- |
| `Schema::string()` | String | `min()`, `max()`, `pattern()`, `format()`, `default()` |
| `Schema::integer()` | Integer | `min()`, `max()`, `multipleOf()`, `default()` |
| `Schema::number()` | Number (float) | `min()`, `max()`, `multipleOf()`, `default()` |
| `Schema::boolean()` | Boolean | `default()` |
| `Schema::array()` | Array | `items()`, `min()`, `max()`, `unique()`, `default()` |
| `Schema::object()` | Object | `withoutAdditionalProperties()`, `default()` |

## Tools

Tools enable LLMs to call functions during text generation. Prisma supports both custom tools (executed locally) and provider tools (executed server-side by the LLM provider).

### Creating tools

Create tools using the `Tools` facade:

**From scratch:**

```php
use Aimeos\\Prisma\\Schema\\Schema;
use Aimeos\\Prisma\\Tools;

$tool = Tools::make( 'search', 'Search the web', Schema::for( 'search', [
    'query' => Schema::string()->description( 'Search query' )->required(),
] ), fn( $args ) => file_get_contents( 'https://api.example.com/search?q=' . $args['query'] ) );
```

**From a Laravel AI / Prism tool:**

```php
$tool = Tools::laravel( new MyLaravelTool() );
```

The object must have `name()`, `description()`, and `toArray()` methods. Execution uses `__invoke()` or `handle()`.

**From a Symfony #[AsTool] class:**

```php
$tool = Tools::symfony( MySymfonyTool::class );
// or with a specific tool name when the class has multiple #[AsTool] attributes:
$tool = Tools::symfony( MySymfonyTool::class, 'tool-name' );
```

**Using tools with a provider:**

```php
use Aimeos\Prisma\Prisma;
use Aimeos\\Prisma\\Schema\\Schema;
use Aimeos\\Prisma\\Tools;

$tool = Tools::make( 'weather', 'Get current weather', Schema::for( 'weather', [
    'city' => Schema::string()->description( 'City name' )->required(),
] ), fn( $args ) => json_encode( ['temp' => '22°C', 'city' => $args['city']] ) );

$response = Prisma::text()
    ->using( 'openai', ['api_key' => 'xxx'] )
    ->withTools( [$tool] )
    ->withMaxSteps( 5 )
    ->write( 'What is the weather in Berlin?' );
```

`withMaxSteps()` controls the maximum number of tool calls performed (default is unlimited).

> **Note:** Tool handlers can return any value. Strings are passed through as-is; all other return types (arrays, objects, numbers) are automatically JSON-encoded.

**Tool choice:**

`withToolChoice()` controls whether the model must use tools. Values: `auto` (default, model decides), `required` (must use a tool), `none` (no tools).

```php
->withToolChoice( 'required' )
```

**Limiting tool calls:**

```php
$tool = Tools::make( ... )->max( 3 ); // This specific tool can only be called 3 times
```

### Provider tools

Provider tools are built-in tools executed server-side by the LLM provider (e.g., web search, code execution). They don't require local function handlers. Create them using `Tools::provider()`:

```php
use Aimeos\Prisma\Prisma;
use Aimeos\Prisma\Tools;

$response = Prisma::text()
    ->using( 'anthropic', ['api_key' => 'xxx'] )
    ->withTools( [
        Tools::provider( 'web_search' ),
        Tools::provider( 'code_execution' ),
    ] )
    ->write( 'Search for the latest PHP version and write code to check it' );
```

**Available provider tools:**

| Tool name | Providers |
| :--- | :--- |
| `web_search` | Anthropic, OpenAI, Gemini, Mistral, xAI, OpenRouter, Alibaba |
| `web_search_premium` | Mistral |
| `code_execution` | Anthropic, OpenAI, Gemini, Mistral, xAI |
| `web_fetch` | Anthropic |
| `file_search` | OpenAI |
| `image_generation` | Mistral |
| `document_library` | Mistral |

Provider tool names not supported by the chosen provider are silently ignored. Providers without any provider tool support (e.g. Bedrock, Cohere, Deepseek, Perplexity) ignore all provider tools.

Custom and provider tools can be mixed in a single `withTools()` call:

```php
$response = Prisma::text()
    ->using( 'anthropic', ['api_key' => 'xxx'] )
    ->withTools( [
        $customTool,
        Tools::provider( 'web_search' ),
        Tools::provider( 'code_execution' ),
    ] )
    ->withMaxSteps( 5 )
    ->write( 'Search and analyze' );
```

Pass provider-specific options using `with()`:

```php
Tools::provider( 'web_search' )->with( [
    'allowed_domains' => ['example.com', 'docs.example.com'],
    'blocked_domains' => ['spam.com'],
] )
```

Unknown or unsupported options are silently ignored by each provider.

**Normalized options** (translated automatically per provider):

| Option | Description | Supported by |
| :--- | :--- | :--- |
| `allowed_domains` | Only include results from these domains | Anthropic, OpenAI, OpenRouter |
| `blocked_domains` | Exclude results from these domains | Anthropic, xAI, OpenRouter |
| `search_context_size` | Search depth: `"low"`, `"medium"`, `"high"` | OpenAI, xAI |
| `user_location` | User location object for localized results | OpenAI, Anthropic |

**Provider-specific options:**

| Option | Provider | Tool | Description |
| :--- | :--- | :--- | :--- |
| `max_uses` | Anthropic | web_search, web_fetch | Max server-side uses (also set via `->max()`) |
| `search_engine` | OpenRouter | web_search | `"auto"`, `"native"`, `"exa"` |
| `container` | OpenAI | code_execution | Container config (`['type' => 'auto']`) |
| `vector_store_ids` | OpenAI | file_search | Vector store IDs to search |
| `max_num_results` | OpenAI | file_search | Max results returned |
| `library_ids` | Mistral | document_library | Document library IDs |

### Tool state

Check a tool's remaining call budget using:

```php
$tool = Tools::make( ... )->max( 3 );

$tool->counter(); // 3 — remaining calls
$tool->can();     // true — still callable

// after the model has called the tool 3 times:
$tool->counter(); // 0
$tool->can();     // false
```

### Error handling

By default, when a tool handler throws an exception, the error message is returned to the model as `"Error: {message}"` instead of propagating the exception. You can override this with a custom error handler using `failed()`:

```php
$tool = Tools::make( 'search', 'Search the web', $schema, fn( $args ) => doSearch( $args ) )
    ->failed( function( \Throwable $e, array $arguments ) : string {
        Log::error( 'Tool failed', ['error' => $e->getMessage(), 'args' => $arguments] );
        return 'Search is currently unavailable, please try a different approach.';
    } );
```

The handler receives the thrown exception and the original arguments, and must return a string that is sent back to the model.

### Concurrent tools

Tools marked as concurrent run in parallel when the auto-detected concurrency strategy supports it (`Fork` when PHP *pcntl* extension is available, otherwise `Sequential`):

```php
$schema = Schema::for( 'tool' );

$search = Tools::make( 'search', 'Search the web', $schema, fn( $args ) => '...' )->concurrent();
$weather = Tools::make( 'weather', 'Get weather', $schema, fn( $args ) => '...' )->concurrent();
$save = Tools::make( 'save', 'Save to database', $schema, fn( $args ) => '...' ); // sequential (default)
```

When the LLM calls multiple tools in a single step, concurrent tools run in parallel while sequential tools run one after another. You can also disable concurrency again:

```php
$tool->concurrent( false );
```

**Concurrency strategy:**

Prisma auto-detects the best concurrency strategy: `Fork` (parallel via `pcntl_fork`) when available, otherwise `Sequential`. You can set a different strategy:

```php
use Aimeos\Prisma\Tools\Concurrency\Sequential;

$response = Prisma::text()
    ->using( 'openai', ['api_key' => 'xxx'] )
    ->withConcurrency( new Sequential() )
    ->withTools( [$search, $weather] )
    ->write( 'Search and get weather for Berlin' );
```

**Custom concurrency strategy:**

Implement the `Concurrency` interface to use your own execution strategy (e.g., async I/O, thread pools, or framework-specific solutions):

```php
use Aimeos\Prisma\Tools\Concurrency\Concurrency;
use Aimeos\Prisma\Tools\Step;

class ReactConcurrency implements Concurrency
{
    public function run( array $steps ) : array
    {
        foreach( $steps as $step )
        {
            if( $tool = $step->tool() )
            {
                $step->complete( $tool( $step->arguments() ) );
            }
        }

        return $steps;
    }
}
```

Each `$steps` entry is a `Step` object with `tool()`, `arguments()`, `id()`, `name()`, and `result()`. Call `$step->complete()` with the result string.

> **Note:** Read-only tools that don't modify state should be marked as concurrent.

### Decorating tools

Use the `Decorator` abstract class to wrap tools with additional behavior:

```php
use Aimeos\\Prisma\\Tools\Adapter\Decorator;
use Aimeos\\Prisma\\Tools\Adapter\Adapter;

class LoggingTool extends Decorator
{
    private $logger;

    public function __construct( Adapter $adapter, $logger )
    {
        parent::__construct( $adapter );
        $this->logger = $logger;
    }

    public function __invoke( array $arguments ) : string
    {
        $this->logger->info( 'Tool called: ' . $this->name(), $arguments );
        return parent::__invoke( $arguments );
    }
}

$tool = new LoggingTool( Tools::make( 'search', 'Search', $schema, fn( $args ) => '...' ), $logger );
```

Decorators delegate all `Adapter` interface methods to the wrapped tool. Override any [provider method](https://github.com/aimeos/prisma/blob/master/src/Tools/Adapter/Adapter.php) to add custom behavior.

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

### write

Generate text from the given prompt with optional multimodal file inputs (images, audio, documents).

```php
public function write( string $prompt, array $files = [], array $options = [] ) : TextResponse
```

* @param **string** `$prompt` Input prompt for text generation
* @param **array&#60;int, File&#62;** `$files` Files for multimodal input (images, audio, documents)
* @param **array&#60;string, mixed&#62;** `$options` Provider specific options
* @return **TextResponse** Response text

**Supported options:**

* [Alibaba](https://www.alibabacloud.com/help/en/model-studio/model-api-reference/)
* [Anthropic](https://docs.anthropic.com/en/api/messages)
* [Bedrock](https://docs.aws.amazon.com/bedrock/latest/userguide/conversation-inference-call.html)
* [Cohere](https://docs.cohere.com/reference/chat)
* [Deepseek](https://api-docs.deepseek.com/api/create-chat-completion)
* [Gemini](https://ai.google.dev/gemini-api/docs/text-generation)
* [Groq](https://console.groq.com/docs/text-chat)
* [Mistral](https://docs.mistral.ai/api/#tag/chat/operation/chat_completion_v1_chat_completions_post)
* [OpenAI](https://platform.openai.com/docs/api-reference/chat/create)
* [Openrouter](https://openrouter.ai/docs/api-reference/chat-completions)
* [Perplexity](https://docs.perplexity.ai/api-reference/chat-completions)
* [xAI](https://docs.x.ai/api/endpoints#chat-completions)

**Example:**

```php
use Aimeos\Prisma\Prisma;

$textResponse = Prisma::text()
    ->using( 'openai', ['api_key' => 'xxx'])
    ->ensure( 'write' )
    ->write( 'Summarize the benefits of renewable energy' );

$texts = $textResponse->texts(); // ['Renewable energy offers...']
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
