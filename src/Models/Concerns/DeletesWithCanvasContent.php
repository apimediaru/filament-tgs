<?php

namespace ApiMedia\Tgs\Models\Concerns;

/** @internal */
trait DeletesWithCanvasContent
{
    /** @return bool|null */
    public function delete()
    {
        try {
            return $this->getConnection()->transaction(function (): ?bool {
                $deleted = parent::delete();

                if ($deleted === false) {
                    // A later deleting listener can veto after the cascade has already run.
                    throw new CanvasDeletionCancelled;
                }

                return $deleted;
            });
        } catch (CanvasDeletionCancelled) {
            return false;
        }
    }
}
