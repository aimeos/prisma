# imagine()

Implemented.

Use `Prisma::image()->using( 'recraft', ['api_key' => 'xxx'] )`.

Recraft defaults to `recraftv4_1`, or `recraftv4_styles` when `$images` contains
style references. Up to ten style references are accepted and cannot be combined
with `style_id`. The returned style ID is available in response metadata; creating
a reference style incurs credits in addition to generation. Options include `n`,
`size`, `random_seed`, `style`, `style_id`, `style_match`, `negative_prompt`,
`text_layout`, and `controls`, subject to model support. Use `model()` to select
another model, including SVG-generating vector models. SVG generation does not
implement Prisma's embedding-based `vectorize()` contract.

`response_format` accepts `url` (default) or `b64_json`. Results use
`FileResponse`, including metadata and credit usage.
