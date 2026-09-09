# uncrop()

Implemented.

Use `Prisma::image()->using( 'recraft', ['api_key' => 'xxx'] )`.

Recraft defaults to `recraftv3`; this endpoint supports V3 raster/vector models.
The four pixel margins map directly to Recraft's expansion parameters and must
be between 0 and 4096. `size` is rejected because it conflicts with pixel margins.
Options include `prompt` (default `Extend the image naturally`),
`zoom_out_percentage`, `n`, `style`, `style_id`, `style_match`, `negative_prompt`,
`text_layout`, and `controls`.

`response_format` accepts `url` (default) or `b64_json`. Results use
`FileResponse`, including metadata and credit usage.
