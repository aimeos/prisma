# erase()

Implemented.

Use `Prisma::image()->using( 'recraft', ['api_key' => 'xxx'] )`.

Recraft uses Erase Region. The mask must match the input image dimensions,
with white pixels marking the area to erase and black pixels marking the area
to preserve. Raster and SVG inputs are supported.

`response_format` accepts `url` (default) or `b64_json`. Results use
`FileResponse`, including metadata and credit usage.
