<?php

namespace Aimeos\Prisma\Concerns;

use Aimeos\Prisma\Exceptions\BadRequestException;
use Aimeos\Prisma\Files\File;


/**
 * Conversation history handling for text providers.
 */
trait HasMessages
{
    /** @var array<int, array<string, mixed>> */
    private array $messages = [];


    /**
     * Sets the prior conversation turns sent before the current prompt.
     *
     * Each entry is an associative array with a "role" of "user" or "assistant" and
     * a string "content". User turns may add a "files" key with an array of File
     * objects for multimodal input (subject to the provider's file support). System
     * context is set via withSystemPrompt(); tool calls are not part of the history.
     *
     * @param array<int, array<string, mixed>> $messages Conversation turns
     * @return self
     */
    public function withMessages( array $messages ) : self
    {
        $this->messages = $messages;
        return $this;
    }


    /**
     * Returns the validated conversation history.
     *
     * Empty turns (no content and no files) are skipped and assistant turns never
     * carry files. The current prompt is appended by the provider afterwards.
     *
     * @return array<int, array{role: string, content: string, files: array<int, File>}> Normalized turns
     * @throws BadRequestException If a turn is structurally invalid
     */
    protected function history() : array
    {
        $list = [];

        foreach( $this->messages as $msg )
        {
            if( !is_array( $msg ) ) {
                throw new BadRequestException( 'Each message must be an array with "role" and "content"' );
            }

            $role = $msg['role'] ?? null;
            $content = $msg['content'] ?? '';
            $files = $msg['files'] ?? [];

            if( !in_array( $role, ['user', 'assistant'], true ) ) {
                throw new BadRequestException( sprintf( 'Message role must be "user" or "assistant", got "%s"', is_string( $role ) ? $role : gettype( $role ) ) );
            }

            if( !is_string( $content ) ) {
                throw new BadRequestException( 'Message content must be a string' );
            }

            if( !is_array( $files ) ) {
                throw new BadRequestException( 'Message files must be an array of File objects' );
            }

            foreach( $files as $file )
            {
                if( !( $file instanceof File ) ) {
                    throw new BadRequestException( sprintf( 'Message file must be a File object, got %s', get_debug_type( $file ) ) );
                }
            }

            // Assistant turns never carry files and empty turns add nothing, so both
            // are normalized away before the provider maps them to its own format.
            $files = $role === 'assistant' ? [] : $files;

            if( $content === '' && $files === [] ) {
                continue;
            }

            $list[] = ['role' => $role, 'content' => $content, 'files' => $files];
        }

        return $list;
    }
}
