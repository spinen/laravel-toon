<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Minimum Rows for Table Format
    |--------------------------------------------------------------------------
    |
    | Arrays with fewer items than this will be encoded as regular YAML-like
    | objects instead of the compact tabular format. Set to 1 to always use
    | tables for uniform arrays, or higher to only use tables for larger
    | datasets where the header overhead is worth it.
    |
    | Example with min_rows = 2:
    |   1 item  → id: 1\n name: Alice
    |   2 items → items[2]{id,name}:\n  1,Alice\n  2,Bob
    |
    */
    'min_rows_for_table' => 2,

    /*
    |--------------------------------------------------------------------------
    | Maximum Flatten Depth
    |--------------------------------------------------------------------------
    |
    | How many levels deep to flatten nested objects within arrays. Nested
    | objects become dot-notation columns (e.g., author.name, author.email).
    | Objects nested deeper than this limit will be JSON-encoded as a string.
    |
    | Example with max_depth = 2:
    |   user.profile.name → flattened to column
    |   user.profile.settings.theme → JSON string "[{...}]"
    |
    | Increase for deeply nested data structures. Decrease if your objects
    | are very wide (many fields) to keep column headers manageable.
    |
    */
    'max_flatten_depth' => 3,

    /*
    |--------------------------------------------------------------------------
    | Escape Style
    |--------------------------------------------------------------------------
    |
    | How to escape special characters in string values. Special characters
    | include commas (,), colons (:), and newlines which have meaning in the
    | TOON format.
    |
    | Supported styles:
    |   - 'backslash': Escape with backslash (Hello, World → Hello\, World)
    |
    */
    'escape_style' => 'backslash',

];
