<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Widgets;

/**
 * A visualization the model programs itself (ChatGPT's `AppBlock`): its own HTML, CSS and
 * JavaScript with the data in the block, run by the frontend in a sandboxed iframe (`sandbox="allow-scripts"`, an opaque
 * origin, a policy that loads nothing from the network) — `widgets/ai/CustomVisualizationConversationWidget/`.
 * DATA-COMPLETE: the block carries everything the frame shows; the frame fetches nothing. The last resort of the widget
 * kit: only for what no `ComposedView` node can express (a canvas animation, a custom projection, a simulation).
 *
 * The class describes the block and its limits — the source cap and the library allow-list the frontend enforces with
 * the same numbers (`shared/custom-visualization-widget-payload.ts`, `shared/custom-visualization-libraries.ts`), pinned
 * on both sides by `custom-visualization-block-fixture.json`. It never runs or checks the script.
 */
class CustomVisualizationConversationWidget extends ConversationWidget
{
    public const string WIDGET_TYPE = 'CustomVisualization';

    /** Written from what the model has — no skill brings its data (`findWidgets` answers "usable directly"). */
    public const bool IS_WRITABLE_WITHOUT_A_SKILL = true;

    public const string SUMMARY = 'A visualization the model programs itself — its own HTML, CSS and JavaScript with the data in the block, run in a sandboxed frame. ONLY for what no ComposedView node can express (a canvas animation, a custom projection, a simulation).';

    public const string WHEN_TO_USE = 'Only when no ComposedView node can express the picture: an animation on a canvas, a custom projection or a small simulation the owner explores with sliders or the mouse. A table, a chart, KPI tiles, a map, a list or a form are ComposedView nodes, never this widget. One short line first, then the widget; the prose carries the key statement, because a visualization that fails to load shows nothing. Never for live data the script would fetch (it cannot), never for demo numbers in a customer answer.';

    public const string OWNER_CONTROL = 'none (interactive visualization, local only)';

    public const string DIRECT_WIDGET_WHEN = 'the owner asks for a picture that moves or plays out by itself, or that no layout of nodes can draw — an animation, a simulation, a free drawing — built from what the owner said';

    public const bool EMBEDDABLE = true;

    public const string EMBEDDED_SIZE_CLASS = 'full';

    /** The most source a block may carry: the UTF-8 bytes of html + css + script + the JSON of data (frontend twin). */
    public const int MAX_SOURCE_BYTES = 65536;

    /** @var string[] The libraries the sandbox may load, by the name the block writes (frontend twin). */
    public const array ALLOWED_LIBRARY_NAMES = ['d3'];

    /** The frame's height range in CSS px (frontend twin). */
    public const int MIN_HEIGHT = 80;

    public const int MAX_HEIGHT = 1200;

    /** @var string|null The title, in the owner's language. */
    public ?string $title = null;

    /** @var string|null One caption line. */
    public ?string $description = null;

    /** @var string The markup. */
    public string $html = '';

    /** @var string|null The styles. */
    public ?string $css = null;

    /** @var string|null The JavaScript, reading `window.AGENTS_VISUALIZATION.data`. */
    public ?string $script = null;

    /** @var mixed[]|null `d3` only ({@see ALLOWED_LIBRARY_NAMES}); the block may name anything, the frontend loads only the allowed ones. */
    public ?array $libraries = null;

    /** @var mixed The JSON the script draws. */
    public mixed $data = null;

    /** @var int|null Height in px, 80–1200 ({@see MIN_HEIGHT}, {@see MAX_HEIGHT}), until the frame reports its own. */
    public ?int $height = null;

    /**
     * The bytes the source cap counts: `html`, `css`, `script` and the compact JSON of `data` (nothing for no data) —
     * the frontend's `measureCustomVisualizationSourceBytes()` counts the same.
     */
    public function measureSourceBytes(): int
    {
        $dataJson = '';
        if ($this->data !== null) {
            $encodedData = json_encode($this->data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $dataJson = is_string($encodedData) ? $encodedData : '';
        }
        return strlen($this->html) + strlen((string)$this->css) + strlen((string)$this->script) + strlen($dataJson);
    }

    /** Whether the frontend runs the block: html or script present, every library allowed, the source within the cap. */
    public function isRunnable(): bool
    {
        if (trim($this->html) === '' && trim((string)$this->script) === '') {
            return false;
        }
        return $this->getLibraryNamesOutsideTheAllowList() === [] && $this->measureSourceBytes() <= static::MAX_SOURCE_BYTES;
    }

    /**
     * The library names the block asks for that the sandbox does not serve, as the frontend reads them (trimmed, lower
     * case).
     *
     * @return string[]
     */
    public function getLibraryNamesOutsideTheAllowList(): array
    {
        $libraryNamesOutsideTheAllowList = [];
        foreach ($this->libraries ?? [] as $libraryName) {
            $normalizedLibraryName = is_string($libraryName) ? strtolower(trim($libraryName)) : '';
            if ($normalizedLibraryName !== '' && !in_array($normalizedLibraryName, static::ALLOWED_LIBRARY_NAMES, true)) {
                $libraryNamesOutsideTheAllowList[] = $normalizedLibraryName;
            }
        }
        return array_values(array_unique($libraryNamesOutsideTheAllowList));
    }

    /** The fields next to `type` and the self-hydration key, in block order; unset optional fields are not written. */
    protected function getWidgetFields(): array
    {
        return static::withoutNullWidgetFields([
            'title' => $this->title,
            'description' => $this->description,
            'html' => $this->html,
            'css' => $this->css,
            'script' => $this->script,
            'libraries' => $this->libraries,
            'data' => $this->data,
            'height' => $this->height,
        ]);
    }
}
