<?php

namespace Aimeos\Prisma\Concerns;


/**
 * Model selection for providers.
 */
trait HasModel
{
    private ?string $model = null;


    /**
     * Sets the model to use.
     *
     * @param string|null $model Model name
     * @return self
     */
    public function model( ?string $model ) : self
    {
        $this->model = $model;
        return $this;
    }


    /**
     * Returns the configured model name or a default.
     *
     * @param string|null $default Default model name
     * @return string|null Model name
     */
    protected function modelName( ?string $default = null ) : ?string
    {
        return $this->model ?: $default;
    }
}
