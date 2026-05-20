<?php

namespace Aimeos\Prisma\Values;


/**
 * Citation from a provider response.
 */
class Citation
{
    private readonly ?string $title;
    private readonly ?string $url;
    private readonly ?string $text;
    private readonly ?string $source;


    /**
     * Initializes the citation.
     *
     * @param string|null $title Source title
     * @param string|null $url Source URL
     * @param string|null $text Output text that references the source
     * @param string|null $source Verbatim quote from the source document
     */
    public function __construct( ?string $title = null, ?string $url = null, ?string $text = null, ?string $source = null )
    {
        $this->title = $title;
        $this->url = $url;
        $this->text = $text;
        $this->source = $source;
    }


    /**
     * Returns the verbatim quote from the source document.
     *
     * @return string|null Source quote
     */
    public function source() : ?string
    {
        return $this->source;
    }


    /**
     * Returns the output text that references the source.
     *
     * @return string|null Cited output text
     */
    public function text() : ?string
    {
        return $this->text;
    }


    /**
     * Returns the source title.
     *
     * @return string|null Source title
     */
    public function title() : ?string
    {
        return $this->title;
    }


    /**
     * Returns the source URL.
     *
     * @return string|null Source URL
     */
    public function url() : ?string
    {
        return $this->url;
    }
}
