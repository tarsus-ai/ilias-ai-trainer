<?php

namespace TarsusIlias\Output;

/**
 * The plugin's own line icons (24x24, stroked with the current colour), so
 * the screens look the same whatever icon font the ILIAS skin ships.
 *
 * @author  lern.link GmbH
 * @license GPL-3.0-or-later
 */
final class Icons
{
    /** @var array<string, string> Icon name => SVG inner markup. */
    public const PATHS = [
        'mode_build' => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.6 10.8c.7.5 1.1 1.3 1.1 2.2h5c0-.9.4-1.7 1.1-2.2A6 6 0 0 0 12 3z"/>',
        'mode_test' => '<path d="M6.5 6.5v11M3.5 9v6M17.5 6.5v11M20.5 9v6M6.5 12h11"/>',
        'mode_ask' => '<path d="M20 12a8 8 0 0 1-11.7 7.1L4 20l1.1-4A8 8 0 1 1 20 12z"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.4 2.3c-.6.3-.9.7-.9 1.4M12 16h.01"/>',
        'send' => '<path d="M21 3 10 14M21 3l-6.5 18-4-8-8-4L21 3z"/>',
        'jump_top' => '<path d="M12 19V5M5.5 11.5 12 5l6.5 6.5"/>',
        'audio' => '<path d="M11 5 6 9H3v6h3l5 4V5z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13"/>',
        'reply' => '<path d="M9 14 4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 6 6v4"/>',
        'close' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'audio_play' => '<path d="M8 5.5v13l11-6.5-11-6.5z" fill="currentColor" stroke="none"/>',
        'audio_pause' => '<path d="M7 5h3.5v14H7zM13.5 5H17v14h-3.5z" fill="currentColor" stroke="none"/>',
        'taxonomy_knowledge' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15z"/><path d="M4 20.5A2.5 2.5 0 0 1 6.5 18H20v3H6.5"/>',
        'taxonomy_understanding' => '<path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
        'taxonomy_application' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'continue' => '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5v-15z"/>',
        'chevron' => '<path d="m9 6 6 6-6 6"/>',
        'grade' => '<path d="M2 9 12 4l10 5-10 5L2 9z"/><path d="M6 11v5c3 2.5 9 2.5 12 0v-5"/>',
        'learners' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6.5 6.5 0 0 1 3.5 6"/>',
        'refresh' => '<path d="M20 11a8 8 0 0 0-14.6-4.5L4 8M4 4v4h4"/><path d="M4 13a8 8 0 0 0 14.6 4.5L20 16M20 20v-4h-4"/>',
        'download' => '<path d="M12 3v12M7 10l5 5 5-5M4 19h16"/>',
        'enlarge' => '<path d="M14 4h6v6M10 20H4v-6M20 4l-7 7M4 20l7-7"/>',
        'help' => '<circle cx="12" cy="12" r="9"/><path d="M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.7.3-1 .9-1 1.7M12 17h.01"/>',
        'video' => '<rect x="3" y="6" width="13" height="12" rx="2"/><path d="m16 10 5-3v10l-5-3z"/>',
        'link' => '<path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/>',
        'chart' => '<path d="M4 20V4M4 20h16"/><path d="M8 16v-5M12 16V8M16 16v-3"/>',
        // Processing indicator steps.
        'proc_diagram' => '<rect x="3" y="3" width="7" height="6" rx="1"/><rect x="14" y="15" width="7" height="6" rx="1"/><path d="M6.5 9v4.5h11V15"/>',
        'proc_atom' => '<circle cx="12" cy="12" r="1.6" fill="currentColor"/><ellipse cx="12" cy="12" rx="9.5" ry="3.8"/><ellipse cx="12" cy="12" rx="9.5" ry="3.8" transform="rotate(60 12 12)"/><ellipse cx="12" cy="12" rx="9.5" ry="3.8" transform="rotate(120 12 12)"/>',
        'proc_shapes' => '<circle cx="7" cy="7" r="3.5"/><rect x="13.5" y="13.5" width="7" height="7" rx="1"/><path d="M17 3l3.5 6h-7L17 3z"/>',
        'proc_dot' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3" fill="currentColor"/>',
        'proc_cube' => '<path d="M12 2.5 20.5 7v10L12 21.5 3.5 17V7L12 2.5z"/><path d="M3.5 7 12 11.5 20.5 7M12 11.5v10"/>',
        'proc_gear' => '<circle cx="12" cy="12" r="3.2"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>',
        'proc_sparkle' => '<path d="M12 3l1.8 5.2L19 10l-5.2 1.8L12 17l-1.8-5.2L5 10l5.2-1.8L12 3z"/><path d="M19 15l.7 1.8 1.8.7-1.8.7L19 20l-.7-1.8-1.8-.7 1.8-.7L19 15z"/>',
        'proc_notch' => '<path d="M21 12a9 9 0 1 1-6.2-8.6"/>',
        'proc_sun' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2.5M12 19.5V22M2 12h2.5M19.5 12H22M4.9 4.9l1.8 1.8M17.3 17.3l1.8 1.8M4.9 19.1l1.8-1.8M17.3 6.7l1.8-1.8"/>',
        'proc_chart' => '<path d="M12 3a9 9 0 1 0 9 9h-9V3z"/><path d="M15 2.6A9 9 0 0 1 21.4 9H15V2.6z"/>',
        'proc_asterisk' => '<path d="M12 3v18M4.2 7.5l15.6 9M4.2 16.5l15.6-9"/>',
        'proc_target' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2" fill="currentColor"/>',
        'proc_crosshair' => '<circle cx="12" cy="12" r="8"/><path d="M12 2v5M12 17v5M2 12h5M17 12h5"/>',
        'proc_hurricane' => '<path d="M12 12a2 2 0 1 0 0-.01"/><path d="M5 5c3-2 9-2 12 1M19 19c-3 2-9 2-12-1M5 5c-2 3-2 9 1 12M19 19c2-3 2-9-1-12"/>',
        'proc_star' => '<path d="M12 3l2.8 5.8 6.2.9-4.5 4.4 1.1 6.3L12 17.4l-5.6 3 1.1-6.3L3 9.7l6.2-.9L12 3z"/>',
        'proc_snowflake' => '<path d="M12 2v20M3.3 7l17.4 10M3.3 17l17.4-10"/><path d="M9.5 3.5 12 6l2.5-2.5M9.5 20.5 12 18l2.5 2.5"/>',
        'proc_fan' => '<circle cx="12" cy="12" r="1.8"/><path d="M12 10.2C12 6 13.5 3 16 3c2 0 2.5 2.5 1 4.5-1.3 1.7-3.4 2.4-5 2.7zM13.8 12c4.2 0 7.2 1.5 7.2 4 0 2-2.5 2.5-4.5 1-1.7-1.3-2.4-3.4-2.7-5zM12 13.8C12 18 10.5 21 8 21c-2 0-2.5-2.5-1-4.5 1.3-1.7 3.4-2.4 5-2.7zM10.2 12C6 12 3 10.5 3 8c0-2 2.5-2.5 4.5-1 1.7 1.3 2.4 3.4 2.7 5z"/>',
        'proc_seedling' => '<path d="M12 21v-9"/><path d="M12 12c0-4 3-7 8-7 0 5-3 8-8 7zM12 14c0-3.5-2.5-6-7-6 0 4 2.5 6.5 7 6z"/>',
        'proc_nodes' => '<circle cx="6" cy="6" r="2.5"/><circle cx="18" cy="6" r="2.5"/><circle cx="12" cy="18" r="2.5"/><path d="M8.5 6h7M7.2 8.2l3.6 7.5M16.8 8.2l-3.6 7.5"/>',
    ];
}
