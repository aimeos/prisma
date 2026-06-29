<?php

namespace Aimeos\Prisma\Values;


/**
 * Provider operation details emitted to Prisma observers.
 */
class Observation implements \JsonSerializable
{
    /**
     * Initializes the observation.
     *
     * @param string $operation Provider operation name
     * @param string $type Provider media type (text, image, audio, video)
     * @param string $provider Provider name
     * @param string|null $model Model name, or null if unknown
     * @param float $durationMs Operation duration in milliseconds
     * @param \Throwable|null $error Provider failure, or null when the operation completed successfully
     * @param Usage|null $usage Response usage data, or null if unavailable
     * @param Meta|null $meta Response metadata, or null if unavailable
     */
    public function __construct(
        public readonly string $operation,
        public readonly string $type,
        public readonly string $provider,
        public readonly ?string $model,
        public readonly float $durationMs,
        public readonly ?\Throwable $error = null,
        public readonly ?Usage $usage = null,
        public readonly ?Meta $meta = null
    ) {
    }


    /**
     * Returns the observation data as a JSON-serializable map.
     *
     * @return array{
     *     operation: string,
     *     type: string,
     *     provider: string,
     *     model: string|null,
     *     durationMs: float,
     *     error: string|null,
     *     usage: array<string, mixed>|null,
     *     meta: array<string, mixed>|null
     * }
     */
    public function jsonSerialize() : array
    {
        return $this->toArray();
    }


    /**
     * Returns the observation as an array for logging and serialization.
     *
     * @return array{
     *     operation: string,
     *     type: string,
     *     provider: string,
     *     model: string|null,
     *     durationMs: float,
     *     error: string|null,
     *     usage: array<string, mixed>|null,
     *     meta: array<string, mixed>|null
     * }
     */
    public function toArray() : array
    {
        return [
            'operation' => $this->operation,
            'type' => $this->type,
            'provider' => $this->provider,
            'model' => $this->model,
            'durationMs' => $this->durationMs,
            'error' => $this->error?->getMessage(),
            'usage' => $this->usage?->all(),
            'meta' => $this->meta?->all(),
        ];
    }
}
