# upscale()

Implemented.

Use `Prisma::image()->using( 'recraft', ['api_key' => 'xxx'] )`.

Recraft accepts `mode`: `crisp` (default) or `creative`. Recraft determines
the output resolution, so the `$factor` argument is not applied.

`response_format` accepts `url` (default) or `b64_json`. Results use
`FileResponse`, including metadata and credit usage.
