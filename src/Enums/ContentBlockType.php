<?php

declare(strict_types=1);

namespace ApiMedia\Tgs\Enums;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
enum ContentBlockType: string
{
    case Content = 'content';
    case Picture = 'picture';
    case Gallery = 'gallery';
    case Embedded = 'embedded';
    case Column = 'column';
    case DoubleColumn = 'double_column';
    case Accordion = 'accordion';
    case Collapse = 'collapse';
    case Tabs = 'tabs';
    case Code = 'code';
    case Popup = 'popup';
    case TabItem = 'tab_item';
    case AccordionItem = 'accordion_item';
}
