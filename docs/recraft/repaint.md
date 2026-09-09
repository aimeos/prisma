# repaint()

Implemented.

Use `Prisma::image()->using( 'recraft', ['api_key' => 'xxx'] )`.

Recraft defaults to `recraftv4_1`. The `strength` option defaults to `0.5`;
values range from `0` (minimal change) to `1` (minimal similarity). Other options
include `random_seed`, `n`, `style`, `style_id`, `style_match`, `negative_prompt`,
`text_layout`, and `controls`, subject to model support.

`response_format` accepts `url` (default) or `b64_json`. Results use
`FileResponse`, including metadata and credit usage.
