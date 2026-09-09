# inpaint()

Implemented.

Use `Prisma::image()->using( 'recraft', ['api_key' => 'xxx'] )`.

Recraft defaults to `recraftv3`; this endpoint supports V3 raster/vector models.
The mask must match the image dimensions, with white marking the edited region.
Options include `n`, `style`, `style_id`, `style_match`, `negative_prompt`,
`text_layout`, and `controls`.

`response_format` accepts `url` (default) or `b64_json`. Results use
`FileResponse`, including metadata and credit usage.
