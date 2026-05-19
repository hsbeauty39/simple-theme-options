/**
 * sto-rich-modern-editor.js — mount WordPress block editor (Gutenberg) for STO fields.
 */
(function ($, wp) {
    'use strict';

    if (!wp || !wp.element || !wp.blocks || !wp.blockEditor) {
        return;
    }

    var createElement = wp.element.createElement;
    var useState = wp.element.useState;
    var useMemo = wp.element.useMemo;
    var useCallback = wp.element.useCallback;
    var useEffect = wp.element.useEffect;
    var parse = wp.blocks.parse;
    var serialize = wp.blocks.serialize;
    var BlockEditorProvider = wp.blockEditor.BlockEditorProvider;
    var BlockList = wp.blockEditor.BlockList;
    var WritingFlow = wp.blockEditor.WritingFlow;
    var ObserveTyping = wp.blockEditor.ObserveTyping;
    var BlockTools = wp.blockEditor.BlockTools;
    var SlotFillProvider = wp.components.SlotFillProvider;
    var Popover = wp.components.Popover;

    var coreBlocksRegistered = false;

    function registerCoreBlocksOnce() {
        if (coreBlocksRegistered) {
            return;
        }
        if (wp.blockLibrary && typeof wp.blockLibrary.registerCoreBlocks === 'function') {
            wp.blockLibrary.registerCoreBlocks();
        }
        coreBlocksRegistered = true;
    }

    function parseAllowedBlocks($wrap) {
        var raw = $wrap.attr('data-sto-allowed-blocks') || '';
        if (!raw) {
            return true;
        }
        try {
            var parsed = JSON.parse(raw);
            if (parsed === true) {
                return true;
            }
            if (Array.isArray(parsed) && parsed.length) {
                return parsed;
            }
        } catch (error) {
            return true;
        }
        return true;
    }

    function defaultSettings(mediaUploadEnabled) {
        var base = {};
        if (typeof wp.blockEditor.getDefaultSettings === 'function') {
            base = wp.blockEditor.getDefaultSettings() || {};
        } else if (wp.blockEditor.__experimentalGetDefaultSettings) {
            base = wp.blockEditor.__experimentalGetDefaultSettings() || {};
        }

        var next = Object.assign({}, base, {
            hasFixedToolbar: true,
            focusMode: false,
        });
        if (!mediaUploadEnabled) {
            next.mediaUpload = false;
        }
        return next;
    }

    function RichEditorApp(props) {
        var allowedBlocks = props.allowedBlocks;
        var mediaUploadEnabled = props.mediaUpload;
        var onSerializedChange = props.onSerializedChange;

        var blocksState = useState(function () {
            return parse(props.initialContent || '');
        });
        var blocks = blocksState[0];
        var setBlocks = blocksState[1];

        var settings = useMemo(function () {
            var next = defaultSettings(mediaUploadEnabled);
            if (allowedBlocks !== true) {
                next.allowedBlockTypes = allowedBlocks;
            }
            return next;
        }, [allowedBlocks, mediaUploadEnabled]);

        var onInput = useCallback(
            function (nextBlocks) {
                setBlocks(nextBlocks);
                onSerializedChange(serialize(nextBlocks));
            },
            [onSerializedChange, setBlocks]
        );

        return createElement(
            SlotFillProvider,
            null,
            createElement(
                BlockEditorProvider,
                {
                    value: blocks,
                    onInput: onInput,
                    onChange: onInput,
                    settings: settings,
                },
                createElement(
                    'div',
                    { className: 'sto-rich-modern-editor__canvas-inner' },
                    createElement(BlockTools, null),
                    createElement(
                        ObserveTyping,
                        null,
                        createElement(
                            WritingFlow,
                            null,
                            createElement(BlockList, null)
                        )
                    ),
                    createElement(Popover.Slot, null)
                )
            )
        );
    }

    function mountEditor($wrap) {
        var $textarea = $wrap.find('textarea.sto-rich-modern-editor__input').first();
        var mountNode = $wrap.find('.sto-rich-modern-editor__mount')[0];
        if (!$textarea.length || !mountNode) {
            return;
        }

        registerCoreBlocksOnce();

        var allowedBlocks = parseAllowedBlocks($wrap);
        var mediaUpload = String($wrap.attr('data-sto-media-upload') || '1') === '1';
        var initialContent = $textarea.val() || '';

        var renderApp = function () {
            var app = createElement(RichEditorApp, {
                initialContent: initialContent,
                allowedBlocks: allowedBlocks,
                mediaUpload: mediaUpload,
                onSerializedChange: function (serialized) {
                    $textarea.val(serialized).trigger('change');
                },
            });

            if (wp.element.createRoot) {
                var root = $wrap.data('stoRichModernRoot');
                if (!root) {
                    root = wp.element.createRoot(mountNode);
                    $wrap.data('stoRichModernRoot', root);
                }
                root.render(app);
            } else if (wp.element.render) {
                wp.element.render(app, mountNode);
            }
        };

        $wrap.addClass('sto-rich-modern-editor--initialized');
        renderApp();
    }

    function initRichModernEditors($scope) {
        if (!$scope || !$scope.length) {
            return;
        }

        $scope.find('.sto-rich-modern-editor[data-sto-rich-modern-editor]').each(function () {
            var $wrap = $(this);
            if ($wrap.data('stoRichModernMounted')) {
                return;
            }
            $wrap.data('stoRichModernMounted', true);
            mountEditor($wrap);
        });
    }

    window.stoInitRichModernEditors = initRichModernEditors;

    $(function () {
        initRichModernEditors($(document));
    });
})(jQuery, window.wp);
