<?php

return [
    'collections' => [
        'singular' => 'Block collection',
        'plural' => 'Block collections',
        'name' => 'Name',
        'description' => 'Description',
        'lock_heading' => 'Lock block?',
        'unlock_heading' => 'Unlock block?',
        'lock_description' => 'Editors cannot change this block or its nested content.',
        'lock' => 'Lock',
        'unlock' => 'Unlock',
    ],
    'tgs' => [
        'foreign_block_suffix' => '(shared block, read only)',
        'locked_block_suffix' => '(locked by administrator)',
        'reference' => [
            'from_library' => 'From library',
            'linked' => 'Linked',
            'tooltip' => 'A linked block from the library — edited at the source. “Detach” to make an independent copy.',
            'shared' => 'Shared',
            'shared_tooltip' => 'This block is inserted here by reference — used in multiple places, edits apply everywhere.',
        ],
        'builder' => [
            'label' => 'Blocks',
        ],
        'slots' => [
            'content' => 'Content',
            'left' => 'Left column',
            'right' => 'Right column',
            'bottom_content' => 'Bottom content',
        ],
        'canvas' => [
            'claim_conflict' => 'The canvas is unavailable or already claimed by another owner.',
            'empty' => 'No content',
            'missing' => [
                'heading' => 'Canvas not found',
                'description' => 'The content for this place is unavailable — the canvas was deleted. Remove this item and create it again.',
            ],
            'filled' => 'Filled',
            'not_filled' => 'Not filled',
            'demo_notice' => 'This page is for testing TGS blocks only. It is neither shown nor seeded in production.',
            'demo_tab_edit' => 'Editing',
            'demo_tab_view' => 'View (read-only)',
            'add_block' => 'Add block',
            'add_block_to_slot' => 'Add block to “:slot”',
            'nested_count' => '{1} :count nested block|[2,*] :count nested blocks',
            'delete' => [
                'heading' => 'Delete block?',
                'description' => 'The block and all its nested content will be deleted.',
                'submit' => 'Delete',
                'container_heading' => '{1} Delete “:name” and :count nested block?|[2,*] Delete “:name” and :count nested blocks?',
                'container_nested_note' => '{1} Contains :count nested block, which will also be deleted.|[2,*] Contains :count nested blocks, which will also be deleted.',
                'unlink_heading' => 'Remove block from this page?',
                'unlink_description' => 'The reference to the shared block will be removed from this page. The block itself is unaffected and stays in use elsewhere.',
                'unlink_submit' => 'Remove from page',
                'choice' => [
                    'heading' => 'This block is used in multiple places',
                    'description' => 'Choose what to do with the block.',
                    'field_label' => 'Deletion option',
                    'force' => [
                        'label' => 'Delete everywhere',
                        'description' => 'The block will be removed from every place it is used and moved to the trash. «Restore» brings it back to one place only — the other placements are not restored.',
                    ],
                    'unlink' => [
                        'label' => 'Remove only this placement',
                        'description' => 'The reference will be removed from this page. The block itself stays — it becomes orphaned or stays in use elsewhere.',
                    ],
                ],
            ],
            'detach' => [
                'heading' => 'Detach block?',
                'description' => 'The block becomes an independent copy, no longer linked to the shared original. Future edits to the original will no longer affect it.',
                'nested_toggle' => 'Detach nested references too',
            ],
            'block_modal' => [
                'create' => 'Create :block block',
                'edit' => 'Edit :block block',
                'view' => 'View :block block',
                'usage_note' => '[2,*] This block is used in :count places — edits will show up everywhere it is inserted.',
            ],
            'usage' => [
                'title' => 'Used in',
                'count' => '[2,*] Used in :count places',
                'tooltip' => '[2,*] Places used: :count',
                'embedded_canvas' => 'Embedded canvas #:id',
            ],
            'library' => [
                'added' => 'Added to library',
                'tab_new' => 'New',
                'tab_library' => 'From library',
                'insert_as_label' => 'Insert as:',
                'insert_as_copy' => 'As a copy',
                'insert_as_copy_description' => 'An independent copy — edits won\'t affect the original or any other place it\'s used.',
                'insert_as_reference' => 'As a reference',
                'insert_as_reference_description' => 'A shared block — edits from any place apply everywhere it\'s used.',
                'empty' => 'Library is empty yet',
                'name_field' => [
                    'label' => 'Block name',
                    'helper' => 'Optional',
                ],
                'usage_count_none' => 'Not used anywhere yet',
                'usage_count' => '{1} Used :count time on other pages|[2,*] Used :count times on other pages',
            ],
            'collections' => [
                'tab_collections' => 'Collections',
                'empty' => 'No collections yet',
                'unnamed' => 'Unnamed',
                'blocks_field' => [
                    'label' => 'Blocks to insert',
                ],
            ],
            'token' => [
                'tab_token' => 'By token',
                'heading' => 'Insert by token',
                'field_label' => 'Block ID',
                'insert_button' => 'Insert',
                'errors' => [
                    'not_found' => 'No block found with this ID.',
                    'type_not_allowed' => 'This block type cannot be inserted here.',
                    'cycle' => 'A block cannot be inserted into itself or its own nested content.',
                ],
            ],
            'history' => [
                'title' => 'Block history',
                'empty' => 'No changes yet',
                'media_warning' => 'Media is not reverted: the block\'s images and files are not part of its history.',
                'deleted_user' => 'Deleted',
                'revert' => 'Revert',
                'close' => 'Close',
                'view_diff' => 'View',
                'summary_fields' => '{1} :count field changed|[2,*] :count fields changed',
            ],
            'notifications' => [
                'block_created' => 'Block added',
                'parent_locked' => 'Block locked',
                'parent_locked_body' => 'You cannot add content to a locked block. Ask an administrator to unlock it.',
                'block_updated' => 'Block saved',
                'version_restored' => 'Version restored',
            ],
            'trash' => [
                'counter' => 'Trash (:count)',
                'title' => 'Trash',
                'close' => 'Close',
                'empty' => 'Trash is empty',
                'restore' => 'Restore',
                'restored' => 'Block restored',
                'restored_position_mismatch' => 'Block restored, but to a different spot — the original position is no longer available',
                'system_actor' => 'System',
                'unknown_location' => 'Unknown location',
            ],
        ],
        'orphaned_blocks' => [
            'empty' => 'No orphaned blocks',
            'no_usage' => 'Not used anywhere',
            'used_in' => 'Used in',
            'actions' => [
                'edit' => 'Edit',
                'adopt' => 'Move to library',
                'delete' => 'Delete everywhere',
            ],
            'delete' => [
                'heading' => 'Delete this block everywhere?',
                'description' => 'The block will be removed from every place it is used and moved to the trash. «Restore» brings it back to one place only — the other placements are not restored.',
            ],
            'adopt' => [
                'heading' => 'Move this block into the library?',
                'description' => 'The block will get an owner (the library) and appear there as its own entry. Its current usage places are unaffected.',
                'success' => 'Block moved into the library',
            ],
        ],
        'actions' => [
            'add' => [
                'label' => 'Add',
            ],
            'add_to_library' => [
                'label' => 'Add to library',
                'replace' => 'Replace the original with a link',
                'replace_helper' => 'The block will be moved to the library, leaving a link in its place. Without this option, a separate copy is added to the library.',
            ],
            'node_menu' => [
                'label' => 'More',
            ],
            'add_block' => [
                'label' => 'Add block',
            ],
            'insert_existing' => [
                'label' => 'Insert existing block',
                'mode' => 'Selection method',
                'mode_model' => 'By model',
                'mode_id' => 'By ID',
                'model' => 'Model',
                'record' => 'Record',
                'block' => 'Block',
                'block_id' => 'Block ID',
                'as_copy' => 'Insert as an independent copy',
            ],
            'detach' => [
                'label' => 'Detach',
            ],
            'lock' => [
                'label' => 'Lock',
            ],
            'unlock' => [
                'label' => 'Unlock',
            ],
            'view_block' => [
                'label' => 'View',
            ],
            'history' => [
                'label' => 'History',
            ],
            'copy_token' => [
                'label' => 'Copy',
            ],
        ],
        'blocks' => [
            'content' => [
                'label' => 'Content',
                'fields' => [
                    'content' => [
                        'label' => 'Content',
                    ],
                ],
            ],
            'double_column' => [
                'label' => 'Two columns',
                'fields' => [
                    'left_column_width' => [
                        'label' => 'Left column width',
                    ],
                    'right_column_width' => [
                        'label' => 'Right column width',
                    ],
                    'is_aligned_vertically' => [
                        'label' => 'Center columns vertically',
                    ],
                    'is_swapped' => [
                        'label' => 'Swap columns',
                        'help' => 'Swap columns on large screens',
                    ],
                    'justification' => [
                        'label' => 'Column alignment',
                    ],
                ],
                'tabs' => [
                    'left' => [
                        'label' => 'Left column',
                    ],
                    'right' => [
                        'label' => 'Right column',
                    ],
                ],
                'sections' => [
                    'settings' => [
                        'label' => 'Settings',
                    ],
                ],
            ],
            'picture' => [
                'label' => 'Image',
                'preview' => 'Has image',
                'preview_with_alt' => 'Has image: :alt',
                'preview_empty' => 'No image',
                'fields' => [
                    'image' => [
                        'label' => 'Image',
                    ],
                    'alt' => [
                        'label' => 'Alternative text',
                        'hint' => '[alt]',
                    ],
                    'title' => [
                        'label' => 'Title',
                        'hint' => '[title]',
                    ],
                    'caption' => [
                        'label' => 'Caption',
                        'help' => 'Displayed below the image',
                    ],
                    'navigate_to' => [
                        'label' => 'Link',
                        'help' => 'Open this link when clicked',
                    ],
                    'media_url' => [
                        'label' => 'Media URL',
                        'help' => 'Open this media in a lightbox when the image is clicked',
                    ],
                    'is_original' => [
                        'label' => 'Use original image',
                        'help' => 'Use the original quality instead of a compressed image; the file may be large',
                    ],
                    'is_lightbox' => [
                        'label' => 'Enable lightbox zoom',
                    ],
                    'classname' => [
                        'label' => 'Additional classes',
                    ],
                    'is_rounded' => [
                        'label' => 'Rounded image',
                    ],
                ],
                'sections' => [
                    'seo' => [
                        'label' => 'Search engine optimization (SEO)',
                    ],
                    'settings' => [
                        'label' => 'Settings',
                    ],
                    'appearance' => [
                        'label' => 'Appearance',
                    ],
                ],
            ],
            'embedded' => [
                'label' => 'Embedded media',
                'fields' => [
                    'source' => [
                        'label' => 'Source',
                        'help' => 'Link to a video on VK, RUTUBE, Dzen or another supported media provider',
                    ],
                    'ratio' => [
                        'label' => 'Aspect ratio',
                    ],
                    '@preview' => [
                        'label' => 'Preview',
                        'help' => 'Enter a source to see a preview',
                        'vendor_error' => 'This media provider is not supported',
                    ],
                    '@toggle_preview' => [
                        'label' => 'Enable preview',
                        'help' => 'Display embedded media in the adjacent column',
                    ],
                ],
            ],
            'accordion' => [
                'label' => 'Accordion',
                'fields' => [
                    'is_open' => [
                        'label' => 'Expanded',
                        'help' => 'Collapsed by default',
                    ],
                    'is_accordion' => [
                        'label' => 'Expand only the active item',
                        'help' => 'By default, each item can be expanded independently',
                    ],
                ],
            ],
            'gallery' => [
                'label' => 'Gallery',
                'preview' => ':count image(s)',
                'preview_empty' => 'No images',
                'fields' => [
                    'images' => [
                        'label' => 'Images',
                    ],
                    'aspect_ratio' => [
                        'label' => 'Aspect ratio',
                        'help' => 'Choose the aspect ratio used in the slider',
                    ],
                    'slide_count' => [
                        'label' => 'Number of slides',
                        'options' => [
                            'one' => 'One slide',
                            'two' => 'Two slides',
                            'three' => 'Three slides',
                            'four' => 'Four slides',
                            'auto' => 'Automatic (based on the number of items)',
                        ],
                    ],
                    'is_lightbox' => [
                        'label' => 'Enable lightbox zoom',
                        'help' => 'Open a larger version of an image when clicked',
                    ],
                    'is_original' => [
                        'label' => 'Use original images',
                        'help' => 'Use the original quality instead of compressed images; the files may be large',
                    ],
                    'is_cropped' => [
                        'label' => 'Crop images',
                        'help' => 'Stretch images to fit the slide',
                    ],
                    'is_rounded' => [
                        'label' => 'Rounded images',
                        'help' => 'Round the image corners',
                    ],
                    'is_centered' => [
                        'label' => 'Center slider',
                        'help' => 'Centering automatically enables loop mode',
                    ],
                    'is_looped' => [
                        'label' => 'Loop mode',
                        'help' => 'Scroll through slides indefinitely',
                    ],
                    'is_rewound' => [
                        'label' => 'Rewind mode',
                        'help' => 'Go to the first slide when clicking next on the last slide. Do not combine with loop mode or centering',
                    ],
                ],
                'sections' => [
                    'images' => [
                        'label' => 'Files',
                    ],
                    'configuration' => [
                        'label' => 'Configuration',
                    ],
                ],
            ],
            'tabs' => [
                'label' => 'Tabs',
            ],
            'collapse' => [
                'label' => 'Collapsible content',
                'fields' => [
                    'title' => [
                        'label' => 'Title',
                    ],
                    'content' => [
                        'label' => 'Content',
                    ],
                    'is_open' => [
                        'label' => 'Expanded',
                        'help' => 'Collapsed by default',
                    ],
                ],
            ],
            'code' => [
                'label' => 'Code',
                'fields' => [
                    'code' => [
                        'label' => 'Code',
                    ],
                    'is_pure' => [
                        'label' => 'Raw code',
                        'help' => 'Render code without additional wrappers',
                    ],
                ],
            ],
            'popup' => [
                'label' => 'Popup',
                'fields' => [
                    'content' => [
                        'label' => 'Content',
                    ],
                    'size' => [
                        'label' => 'Window size',
                    ],
                    'button_label' => [
                        'label' => 'Button text',
                        'help' => 'Default: "Read more"',
                    ],
                    'button_position' => [
                        'label' => 'Button position',
                    ],
                    'button_theme' => [
                        'label' => 'Button color scheme',
                    ],
                    'button_variant' => [
                        'label' => 'Button variant',
                    ],
                    'button_class' => [
                        'label' => 'Additional button classes',
                    ],
                    'button_alignment' => [
                        'label' => 'Button alignment',
                        'help' => 'Centered by default',
                    ],
                ],
                'tabs' => [
                    'main' => [
                        'label' => 'General',
                    ],
                    'appearance' => [
                        'label' => 'Appearance',
                    ],
                ],
            ],
            'column' => [
                'label' => 'Column',
            ],
            'tab_item' => [
                'label' => 'Tab',
                'add' => 'Add tab',
                'fields' => [
                    'title' => [
                        'label' => 'Title',
                    ],
                ],
            ],
            'accordion_item' => [
                'label' => 'Accordion item',
                'add' => 'Add accordion item',
                'fields' => [
                    'title' => [
                        'label' => 'Title',
                    ],
                    'is_open' => [
                        'label' => 'Open by default',
                    ],
                ],
            ],
            'contest_participants' => [
                'label' => 'Contest participants',
            ],
            'forecasts' => [
                'label' => 'Predictions',
                'default_heading' => 'Predictions',
                'fields' => [
                    'only_battle' => [
                        'label' => 'Prediction battle only',
                    ],
                    'sport' => [
                        'label' => 'Sport',
                    ],
                    'tournaments' => [
                        'label' => 'Tournaments',
                    ],
                    'competitions' => [
                        'label' => 'Competitions',
                    ],
                    'limit' => [
                        'label' => 'Limit',
                        'help' => 'Maximum number of predictions in the block. Leave empty for no limit',
                    ],
                    'display_limit' => [
                        'label' => 'Display limit',
                        'help' => 'Number of predictions shown initially, with the rest behind Show more. Leave empty to show all',
                    ],
                ],
            ],
        ],
        'settings' => [
            'groups' => [
                'dimensions' => [
                    'label' => 'Dimensions',
                ],
                'visual' => [
                    'label' => 'Appearance',
                ],
                'heading' => [
                    'label' => 'Heading',
                ],
            ],
            'fields' => [
                'section_heading' => [
                    'label' => 'Section heading',
                ],
                'section_heading_tag' => [
                    'label' => 'Heading level',
                    'help' => 'The heading level is determined automatically from the nesting depth by default',
                ],
                'section_heading_display' => [
                    'label' => 'Heading appearance',
                    'help' => 'Changes the appearance without changing the heading level',
                ],
                'section_heading_classname' => [
                    'label' => 'Heading classes',
                    'help' => 'Additional classes appended to the default heading classes',
                ],
                'section_bg' => [
                    'label' => 'Background color',
                ],
                'section_classname' => [
                    'label' => 'Section classes',
                    'help' => 'Additional classes appended to the default section classes',
                ],
                'section_size' => [
                    'label' => 'Section size',
                ],
                'section_position' => [
                    'label' => 'Section position',
                ],
                'container_size' => [
                    'label' => 'Size',
                ],
                'container_position' => [
                    'label' => 'Position',
                ],
            ],
            'tabs' => [
                'data' => [
                    'label' => 'General',
                ],
                'heading' => [
                    'label' => 'Heading',
                ],
                'section' => [
                    'label' => 'Section',
                ],
                'container' => [
                    'label' => 'Container',
                ],
            ],
        ],
        'enums' => [
            'lock_mode' => [
                'surface' => 'Surface (wrapper only)',
                'cascade' => 'Cascade (entire subtree)',
            ],
            'container_size' => [
                'small' => 'Small',
                'medium' => 'Medium',
                'large' => 'Large',
                'full' => 'Full screen (no padding)',
                'fluid' => 'Full screen (with padding)',
            ],
            'position' => [
                'left' => 'Left',
                'center' => 'Center',
                'right' => 'Right',
            ],
            'section_size' => [
                'one_quarter' => '25%',
                'half' => '50%',
                'three_quarters' => '75%',
                'full' => '100%',
            ],
            'column_width' => [
                'one_quarter' => '25%',
                'one_third' => '33%',
                'half' => '50%',
                'two_thirds' => '66%',
                'three_quarters' => '75%',
                'full' => '100%',
                'auto' => 'Auto',
            ],
            'justification' => [
                'start' => 'Start',
                'center' => 'Center',
                'end' => 'End',
                'between' => 'Space between',
                'around' => 'Space around',
                'evenly' => 'Space evenly',
            ],
            'popup_size' => [
                'small' => 'Small',
                'medium' => 'Medium',
                'large' => 'Large',
                'full' => 'Full screen',
            ],
            'button_theme' => [
                'primary' => 'Primary',
                'secondary' => 'Secondary',
                'neutral' => 'Neutral',
                'danger' => 'Danger',
                'success' => 'Success',
                'warning' => 'Warning',
                'info' => 'Info',
                'dark' => 'Dark',
                'light' => 'Light',
            ],
            'button_variant' => [
                'solid' => 'Solid',
                'outline' => 'Outline',
                'ghost' => 'Ghost',
                'neon' => 'Neon',
            ],
        ],
        'insertion_forbidden' => 'This block type cannot be inserted at this location.',
        'categories' => [
            'content' => 'Content',
            'media' => 'Media',
            'layout' => 'Layout',
        ],
    ],
    'tab' => [
        'general' => 'General',
    ],
    'forms' => [
        'presets' => [
            'embedded_with_preview' => [
                'fields' => [
                    'source' => [
                        'label' => 'Source',
                        'help' => 'Link to a video on VK, RUTUBE, Dzen or another supported media provider',
                    ],
                    'toggle' => [
                        'label' => 'Enable preview',
                        'help' => 'Display embedded media in the adjacent column',
                    ],
                    'preview' => [
                        'label' => 'Preview',
                        'help' => 'Enter a source to see a preview',
                    ],
                ],
            ],
        ],
    ],
    'settings' => [
        'pages' => [
            'block_library' => [
                'label' => 'Block library',
            ],
            'orphaned_blocks' => [
                'label' => 'Orphaned blocks',
            ],
        ],
    ],
];
