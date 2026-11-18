<?php

namespace ApiMedia\Tgs\Support;

use ApiMedia\Documentation\DocumentationPlugin;

final class TgsDocumentation
{
    public static function url(string $chapter = 'add-block'): ?string
    {
        if (! class_exists(DocumentationPlugin::class)) {
            return null;
        }

        return DocumentationPlugin::url('tgs', $chapter);
    }
}
