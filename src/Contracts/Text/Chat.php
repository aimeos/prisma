<?php

namespace Aimeos\Prisma\Contracts\Text;

use Aimeos\Prisma\Responses\TextResponse;


interface Chat
{
    /**
     * Generates streamed text from the given prompt.
     *
     * The callback is invoked once per streamed text delta (passed as a string) and, for
     * each executed tool call, twice with a \Aimeos\Prisma\Tools\Step - before execution
     * (done() === false) and after it completed (done() === true). A tool call that exhausted
     * its configured limit is not executed and is reported once (completed). The fully
     * assembled response is returned as usual once the stream finished.
     *
     * Only answer text is streamed to the callback; reasoning/thinking is not forwarded
     * but remains available on the returned response via meta().
     *
     * @param string $prompt Input prompt for text generation
     * @param array<int, \Aimeos\Prisma\Files\File> $files Files for multimodal input (images, audio, documents)
     * @param array<string, mixed> $options Provider specific options
     * @param callable|null $callback Stream consumer: fn(string|\Aimeos\Prisma\Tools\Step $chunk): void
     * @return TextResponse Response text
     */
    public function chat( string $prompt, array $files = [], array $options = [], ?callable $callback = null ) : TextResponse;
}
