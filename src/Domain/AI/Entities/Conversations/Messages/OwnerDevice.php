<?php

declare(strict_types=1);

namespace DDD\Domain\AI\Entities\Conversations\Messages;

use DDD\Domain\Base\Entities\ValueObject;

/**
 * The device an OWNER message was sent from — a property of the
 * message, never of the conversation: the owner starts on the phone and continues on the laptop, and every row keeps
 * the device it came from. Persisted as the JSON column {@see AIConversationMessage::$ownerDevice} on owner rows only.
 *
 * Built by the platform, never by the model: {@see fromRequestHints()} reads the client's `X-Rc-Client` header first
 * (the explicit declaration of the web frontend and the native app) and falls back to a small user-agent classifier;
 * the three platform channels ({@see phoneCall()}, {@see externalAgent()}, {@see adminConsole()}) have no request to
 * read. The wire renders {@see toModelTag()} inside the owner message's time bracket (`[time | via iPhone app]`) with
 * honest granularity: a device model only when the app sent one, never "MacBook" from a browser.
 *
 * @phpstan-consistent-constructor
 */
class OwnerDevice extends ValueObject
{
    public const string KIND_MOBILE_APP = 'MOBILE_APP';
    public const string KIND_WEB_BROWSER = 'WEB_BROWSER';
    public const string KIND_MOBILE_WEB_BROWSER = 'MOBILE_WEB_BROWSER';
    public const string KIND_PHONE_CALL = 'PHONE_CALL';
    public const string KIND_EXTERNAL_AGENT = 'EXTERNAL_AGENT';
    public const string KIND_ADMIN_CONSOLE = 'ADMIN_CONSOLE';
    public const string KIND_UNKNOWN = 'UNKNOWN';

    public const array KINDS = [
        self::KIND_MOBILE_APP, self::KIND_WEB_BROWSER, self::KIND_MOBILE_WEB_BROWSER,
        self::KIND_PHONE_CALL, self::KIND_EXTERNAL_AGENT, self::KIND_ADMIN_CONSOLE, self::KIND_UNKNOWN,
    ];

    /** The header's lowercase kind tokens → the kind constants (the constant names are accepted too). */
    public const array HEADER_KIND_TOKENS = [
        'mobileapp' => self::KIND_MOBILE_APP,
        'app' => self::KIND_MOBILE_APP,
        'web' => self::KIND_WEB_BROWSER,
        'desktopweb' => self::KIND_WEB_BROWSER,
        'mobileweb' => self::KIND_MOBILE_WEB_BROWSER,
    ];

    public const string OS_IOS = 'iOS';
    public const string OS_IPADOS = 'iPadOS';
    public const string OS_ANDROID = 'Android';
    public const string OS_MACOS = 'macOS';
    public const string OS_WINDOWS = 'Windows';
    public const string OS_LINUX = 'Linux';
    public const string OS_CHROMEOS = 'ChromeOS';

    public const string DEVICE_CLASS_PHONE = 'phone';
    public const string DEVICE_CLASS_TABLET = 'tablet';
    public const string DEVICE_CLASS_DESKTOP = 'desktop';

    /** How the value object was derived: the client's header, the user agent, or the platform's own channel. */
    public const string SOURCE_HEADER = 'header';
    public const string SOURCE_USER_AGENT = 'userAgent';
    public const string SOURCE_PLATFORM = 'platform';

    /** The header the web frontend and the native app send: `X-Rc-Client: <kind>; os=<os>; osVersion=<v>; device=<model>; browser=<name>; appVersion=<v>`. */
    public const string CLIENT_HEADER_NAME = 'X-Rc-Client';

    /**
     * The transient `surface` variant dimension: derived per model call from the newest owner
     * text row that carries a device and merged into the RENDERED variants only — never persisted on the working
     * state, because the owner starts on the phone and continues on the laptop. Skill sections key on it as
     * `## [surface=mobileApp|mobileWeb] …`; an absent dimension hides such sections.
     */
    public const string SURFACE_DIMENSION = 'surface';
    public const string SURFACE_MOBILE_APP = 'mobileApp';
    public const string SURFACE_MOBILE_WEB = 'mobileWeb';
    public const string SURFACE_DESKTOP_WEB = 'desktopWeb';
    public const string SURFACE_PHONE = 'phone';
    public const array SURFACES = [self::SURFACE_MOBILE_APP, self::SURFACE_MOBILE_WEB, self::SURFACE_DESKTOP_WEB, self::SURFACE_PHONE];

    /**
     * The selector value `screen` names every surface with a screen. Skills and tools write only
     * `[surface=screen]` and `[surface=phone]`; the screen is the default — a conversation without a decided surface
     * renders the screen sections.
     */
    public const string SURFACE_SCREEN = 'screen';
    public const array SURFACE_SCREEN_VALUES = [self::SURFACE_MOBILE_APP, self::SURFACE_MOBILE_WEB, self::SURFACE_DESKTOP_WEB];

    /**
     * Whether a section whose ONLY selector is `surface=screen` or `surface=phone` renders for the decided surface
     * `$surfaceValue` (null = undecided → the screen default); null when `$selectorValue` is neither — then the plain
     * selector matching applies.
     */
    public static function matchesDefaultedSurfaceSelector(string $selectorValue, ?string $surfaceValue): ?bool
    {
        return match ($selectorValue) {
            self::SURFACE_SCREEN => $surfaceValue !== self::SURFACE_PHONE,
            self::SURFACE_PHONE => $surfaceValue === self::SURFACE_PHONE,
            default => null,
        };
    }

    // Known honesty gaps of the user-agent fallback (the header wins when sent): an iPad Safari that requests the
    // desktop site sends a Macintosh user agent and reads as a Mac; CFNetwork also names macOS URLSession clients
    // and okhttp any JVM client — both read as the app. The web frontend's header tells the iPad by its touch points.

    /** @var string One of {@see KINDS}. */
    public string $kind = self::KIND_UNKNOWN;

    /** @var string|null One of the `OS_*` constants, or null when unknown. */
    public ?string $operatingSystem = null;

    /** @var string|null The OS version as the client reported it (`17.5`, `14`, `10.15.7`). */
    public ?string $operatingSystemVersion = null;

    /** @var string|null phone / tablet / desktop, or null when unknown. */
    public ?string $deviceClass = null;

    /** @var string|null The device identifier the app header sent (`iPhone16,2`, `Samsung SM-S928B`); a browser never yields one. Rendered to the model only as a name the Apple map knows. */
    public ?string $deviceModel = null;

    /** @var string|null Safari / Chrome / Firefox / Edge / Samsung Internet, or null (an app, a platform channel). */
    public ?string $browser = null;

    /** @var string|null The native app's version — persisted for support, never rendered to the model. */
    public ?string $appVersion = null;

    /** @var string One of the `SOURCE_*` constants. */
    public string $source = self::SOURCE_PLATFORM;

    /**
     * The device of an HTTP request: the explicit `X-Rc-Client` header wins; without one (or with an unreadable kind)
     * the user agent is classified. Never throws; a request with neither yields the UNKNOWN kind, which renders no tag.
     */
    public static function fromRequestHints(?string $clientHeader, ?string $userAgent): self
    {
        $fromHeader = self::fromClientHeader($clientHeader);
        if ($fromHeader === null) {
            return self::fromUserAgent($userAgent);
        }
        // the header may leave the OS or browser blank — the user agent fills what it can, the header's values win
        $fromUserAgent = self::fromUserAgent($userAgent);
        $fromHeader->operatingSystem ??= $fromUserAgent->operatingSystem;
        $fromHeader->operatingSystemVersion ??= $fromUserAgent->operatingSystemVersion;
        $fromHeader->deviceClass = self::defaultDeviceClassForKind($fromHeader->kind, $fromHeader->operatingSystem);
        if ($fromHeader->kind !== self::KIND_MOBILE_APP) {
            $fromHeader->browser ??= $fromUserAgent->browser;
        }
        return $fromHeader;
    }

    /** The platform's own channel: a transcript row of a phone call. */
    public static function phoneCall(): self
    {
        return self::platformChannel(self::KIND_PHONE_CALL);
    }

    /** The platform's own channel: a transcript row of an in-app voice call — the owner speaks in the app. */
    public static function inAppVoiceCall(): self
    {
        return self::platformChannel(self::KIND_MOBILE_APP);
    }

    /** The platform's own channel: a request an external software agent sent (A2A). */
    public static function externalAgent(): self
    {
        return self::platformChannel(self::KIND_EXTERNAL_AGENT);
    }

    /** The platform's own channel: an admin typing in the admin console. */
    public static function adminConsole(): self
    {
        return self::platformChannel(self::KIND_ADMIN_CONSOLE);
    }

    protected static function platformChannel(string $kind): static
    {
        $ownerDevice = new static();
        $ownerDevice->kind = $kind;
        $ownerDevice->source = self::SOURCE_PLATFORM;
        return $ownerDevice;
    }

    /**
     * Parse `X-Rc-Client`. Null when the header is absent or carries no readable kind — the caller then classifies
     * the user agent. Unknown attributes are ignored; a known attribute with an empty value stays null.
     */
    public static function fromClientHeader(?string $clientHeader): ?static
    {
        $clientHeader = trim((string)$clientHeader);
        if ($clientHeader === '') {
            return null;
        }
        $parts = array_map('trim', explode(';', $clientHeader));
        // only the client kinds: the platform channels (phone call, external agent, admin console) are never a
        // client's claim — a browser that sends `PHONE_CALL` is read by its user agent instead
        $kind = self::HEADER_KIND_TOKENS[strtolower((string)array_shift($parts))] ?? null;
        if ($kind === null) {
            return null;
        }
        $attributes = [];
        foreach ($parts as $part) {
            if (!str_contains($part, '=')) {
                continue;
            }
            [$attributeName, $attributeValue] = array_map('trim', explode('=', $part, 2));
            $attributes[strtolower($attributeName)] = self::cleanAttributeValue($attributeValue);
        }
        $ownerDevice = new static();
        $ownerDevice->kind = $kind;
        $ownerDevice->source = self::SOURCE_HEADER;
        $ownerDevice->operatingSystem = self::canonicalOperatingSystem($attributes['os'] ?? null);
        $ownerDevice->operatingSystemVersion = $attributes['osversion'] ?? null;
        $ownerDevice->browser = $kind === self::KIND_MOBILE_APP ? null : self::canonicalBrowser($attributes['browser'] ?? null);
        $ownerDevice->appVersion = $attributes['appversion'] ?? null;
        $deviceIdentifier = $attributes['device'] ?? null;
        if ($deviceIdentifier !== null) {
            // the identifier as sent (`iPhone16,2`, `Samsung SM-S928B`) — the trace line shows it; the model tag
            // renders a name only when the Apple map knows it ({@see describeAppDevice()})
            $ownerDevice->deviceModel = $deviceIdentifier;
            // an iPad identifier fixes the OS and the class even when the header said only "iOS"
            if (str_starts_with($deviceIdentifier, 'iPad')) {
                $ownerDevice->operatingSystem = self::OS_IPADOS;
            }
        }
        $ownerDevice->deviceClass = self::defaultDeviceClassForKind($kind, $ownerDevice->operatingSystem);
        return $ownerDevice;
    }

    /**
     * The hand-written user-agent classifier (no vendor library): eight families suffice for the bracket's honesty —
     * the native app's HTTP stacks (CFNetwork, okhttp, Dart), iPhone, iPad, Android, Macintosh, Windows, Linux and
     * ChromeOS, plus the browser name. Anything else is UNKNOWN.
     */
    public static function fromUserAgent(?string $userAgent): static
    {
        $ownerDevice = new static();
        $ownerDevice->source = self::SOURCE_USER_AGENT;
        $userAgent = trim((string)$userAgent);
        if ($userAgent === '') {
            return $ownerDevice;
        }
        // The native app's HTTP stacks (until the app sends the header): no browser, no model name.
        if (preg_match('/\bCFNetwork\b/i', $userAgent) === 1) {
            $ownerDevice->kind = self::KIND_MOBILE_APP;
            $ownerDevice->operatingSystem = self::OS_IOS;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_PHONE;
            return $ownerDevice;
        }
        if (preg_match('/\bokhttp\b/i', $userAgent) === 1) {
            $ownerDevice->kind = self::KIND_MOBILE_APP;
            $ownerDevice->operatingSystem = self::OS_ANDROID;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_PHONE;
            return $ownerDevice;
        }
        if (preg_match('/^Dart\//i', $userAgent) === 1) {
            $ownerDevice->kind = self::KIND_MOBILE_APP;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_PHONE;
            return $ownerDevice;
        }
        if (preg_match('/\biPhone\b/', $userAgent) === 1) {
            $ownerDevice->operatingSystem = self::OS_IOS;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_PHONE;
            $ownerDevice->kind = self::KIND_MOBILE_WEB_BROWSER;
            $ownerDevice->operatingSystemVersion = self::matchVersion('/\bOS (\d+(?:_\d+)*)/', $userAgent);
        } elseif (preg_match('/\biPad\b/', $userAgent) === 1) {
            $ownerDevice->operatingSystem = self::OS_IPADOS;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_TABLET;
            $ownerDevice->kind = self::KIND_MOBILE_WEB_BROWSER;
            $ownerDevice->operatingSystemVersion = self::matchVersion('/\bOS (\d+(?:_\d+)*)/', $userAgent);
        } elseif (preg_match('/\bAndroid\b/', $userAgent) === 1) {
            $ownerDevice->operatingSystem = self::OS_ANDROID;
            $ownerDevice->deviceClass = preg_match('/\bMobile\b/', $userAgent) === 1 ? self::DEVICE_CLASS_PHONE : self::DEVICE_CLASS_TABLET;
            $ownerDevice->kind = self::KIND_MOBILE_WEB_BROWSER;
            $ownerDevice->operatingSystemVersion = self::matchVersion('/\bAndroid (\d+(?:\.\d+)*)/', $userAgent);
        } elseif (preg_match('/\bCrOS\b/', $userAgent) === 1) {
            $ownerDevice->operatingSystem = self::OS_CHROMEOS;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_DESKTOP;
            $ownerDevice->kind = self::KIND_WEB_BROWSER;
        } elseif (preg_match('/\bMacintosh\b/', $userAgent) === 1) {
            $ownerDevice->operatingSystem = self::OS_MACOS;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_DESKTOP;
            $ownerDevice->kind = self::KIND_WEB_BROWSER;
            $ownerDevice->operatingSystemVersion = self::matchVersion('/\bMac OS X (\d+(?:_\d+)*)/', $userAgent);
        } elseif (preg_match('/\bWindows NT (\d+(?:\.\d+)*)/', $userAgent, $windowsMatch) === 1) {
            $ownerDevice->operatingSystem = self::OS_WINDOWS;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_DESKTOP;
            $ownerDevice->kind = self::KIND_WEB_BROWSER;
            $ownerDevice->operatingSystemVersion = $windowsMatch[1];
        } elseif (preg_match('/\bLinux\b/', $userAgent) === 1) {
            $ownerDevice->operatingSystem = self::OS_LINUX;
            $ownerDevice->deviceClass = self::DEVICE_CLASS_DESKTOP;
            $ownerDevice->kind = self::KIND_WEB_BROWSER;
        } else {
            return $ownerDevice; // UNKNOWN — a bot, a curl, a stack we do not name
        }
        $ownerDevice->browser = self::browserFromUserAgent($userAgent);
        return $ownerDevice;
    }

    /**
     * A simulated owner's device for the eval harness (`--owner-device=desktopWeb|mobileApp|mobileWeb|phone`): the
     * same classifier paths production takes (a header for the app, a user agent for the browsers), so the eval
     * exercises the bracket the judge reads. Null for an unknown surface name.
     */
    public static function simulated(string $surface): ?self
    {
        return match ($surface) {
            self::SURFACE_MOBILE_APP => self::fromClientHeader('mobileApp; os=iOS; osVersion=17.5; device=iPhone16,2; appVersion=eval'),
            self::SURFACE_MOBILE_WEB => self::fromUserAgent('Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1'),
            self::SURFACE_DESKTOP_WEB => self::fromUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15'),
            self::SURFACE_PHONE => self::phoneCall(),
            default => null,
        };
    }

    /** The `surface` variant value this device implies, or null when it implies none (unknown, external agent, admin console). */
    public function toSurfaceVariantValue(): ?string
    {
        return match ($this->kind) {
            self::KIND_MOBILE_APP => self::SURFACE_MOBILE_APP,
            self::KIND_MOBILE_WEB_BROWSER => self::SURFACE_MOBILE_WEB,
            self::KIND_WEB_BROWSER => self::SURFACE_DESKTOP_WEB,
            self::KIND_PHONE_CALL => self::SURFACE_PHONE,
            default => null,
        };
    }

    /** Whether the owner used a phone or a tablet (the app or the mobile browser). */
    public function isMobile(): bool
    {
        return $this->kind === self::KIND_MOBILE_APP || $this->kind === self::KIND_MOBILE_WEB_BROWSER;
    }

    /**
     * The short tag rendered inside the owner message's wire bracket — three to six tokens, honest granularity.
     * Empty for UNKNOWN (the bracket then renders the time only, today's bytes).
     */
    public function toModelTag(): string
    {
        return match ($this->kind) {
            self::KIND_MOBILE_APP => 'via ' . $this->describeAppDevice() . ' app',
            self::KIND_WEB_BROWSER => 'via web' . $this->describeBrowserOnPlatform(),
            self::KIND_MOBILE_WEB_BROWSER => 'via mobile web' . $this->describeBrowserOnPlatform(),
            self::KIND_PHONE_CALL => 'via phone call',
            self::KIND_EXTERNAL_AGENT => 'via external agent',
            self::KIND_ADMIN_CONSOLE => 'via admin console',
            default => '',
        };
    }

    /** One line for traces and the inspector: `MOBILE_APP iOS 17.5 phone "iPhone 15 Pro Max" app 4.2.1 (header)`. */
    public function toTraceLine(): string
    {
        $parts = [$this->kind];
        if ($this->operatingSystem !== null) {
            $parts[] = $this->operatingSystem . ($this->operatingSystemVersion !== null ? ' ' . $this->operatingSystemVersion : '');
        }
        if ($this->deviceClass !== null) {
            $parts[] = $this->deviceClass;
        }
        if ($this->deviceModel !== null) {
            $parts[] = '"' . (OwnerDeviceAppleModels::humanName($this->deviceModel) ?? $this->deviceModel) . '"';
        }
        if ($this->browser !== null) {
            $parts[] = $this->browser;
        }
        if ($this->appVersion !== null) {
            $parts[] = 'app ' . $this->appVersion;
        }
        return implode(' ', $parts) . ' (' . $this->source . ')';
    }

    protected function describeAppDevice(): string
    {
        // a client's free text never reaches the wire: only a name the Apple map knows, else the OS family
        $knownDeviceName = $this->deviceModel !== null ? OwnerDeviceAppleModels::humanName($this->deviceModel) : null;
        if ($knownDeviceName !== null) {
            return $knownDeviceName;
        }
        return match ($this->operatingSystem) {
            self::OS_IOS => 'iPhone',
            self::OS_IPADOS => 'iPad',
            self::OS_ANDROID => $this->deviceClass === self::DEVICE_CLASS_TABLET ? 'Android tablet' : 'Android',
            default => 'mobile',
        };
    }

    /** `, Safari on Mac` / `, Chrome on Android` / ` on iPhone` — whatever is known, nothing invented. */
    protected function describeBrowserOnPlatform(): string
    {
        $platform = match ($this->operatingSystem) {
            self::OS_IOS => 'iPhone',
            self::OS_IPADOS => 'iPad',
            self::OS_ANDROID => 'Android',
            self::OS_MACOS => 'Mac',
            self::OS_WINDOWS => 'Windows',
            self::OS_LINUX => 'Linux',
            self::OS_CHROMEOS => 'Chromebook',
            default => null,
        };
        if ($this->browser !== null && $platform !== null) {
            return ', ' . $this->browser . ' on ' . $platform;
        }
        if ($this->browser !== null) {
            return ', ' . $this->browser;
        }
        return $platform !== null ? ' on ' . $platform : '';
    }

    protected static function defaultDeviceClassForKind(string $kind, ?string $operatingSystem): ?string
    {
        if ($operatingSystem === self::OS_IPADOS) {
            return self::DEVICE_CLASS_TABLET;
        }
        return match ($kind) {
            self::KIND_MOBILE_APP, self::KIND_MOBILE_WEB_BROWSER => self::DEVICE_CLASS_PHONE,
            self::KIND_WEB_BROWSER => self::DEVICE_CLASS_DESKTOP,
            default => null,
        };
    }

    protected static function canonicalOperatingSystem(?string $raw): ?string
    {
        $token = strtolower(str_replace([' ', '-', '_'], '', trim((string)$raw)));
        return match ($token) {
            'ios', 'iphoneos' => self::OS_IOS,
            'ipados' => self::OS_IPADOS,
            'android' => self::OS_ANDROID,
            'macos', 'osx', 'mac' => self::OS_MACOS,
            'windows', 'win' => self::OS_WINDOWS,
            'linux' => self::OS_LINUX,
            'chromeos', 'cros' => self::OS_CHROMEOS,
            default => null,
        };
    }

    protected static function canonicalBrowser(?string $raw): ?string
    {
        $token = strtolower(str_replace([' ', '-', '_'], '', trim((string)$raw)));
        return match ($token) {
            'safari', 'mobilesafari' => 'Safari',
            'chrome', 'chromium', 'crios' => 'Chrome',
            'firefox', 'fxios' => 'Firefox',
            'edge', 'edg', 'edgios' => 'Edge',
            'samsungbrowser', 'samsunginternet' => 'Samsung Internet',
            default => null,
        };
    }

    protected static function browserFromUserAgent(string $userAgent): ?string
    {
        if (preg_match('/\b(Edg|EdgiOS|EdgA)\//', $userAgent) === 1) {
            return 'Edge';
        }
        if (preg_match('/\bSamsungBrowser\//', $userAgent) === 1) {
            return 'Samsung Internet';
        }
        if (preg_match('/\b(Firefox|FxiOS)\//', $userAgent) === 1) {
            return 'Firefox';
        }
        if (preg_match('/\b(Chrome|CriOS)\//', $userAgent) === 1) {
            return 'Chrome';
        }
        if (preg_match('/\bSafari\//', $userAgent) === 1) {
            return 'Safari';
        }
        return null;
    }

    protected static function matchVersion(string $pattern, string $userAgent): ?string
    {
        if (preg_match($pattern, $userAgent, $match) !== 1) {
            return null;
        }
        return str_replace('_', '.', $match[1]);
    }

    /** Header values are bounded and printable: a client cannot push a paragraph into the bracket. */
    protected static function cleanAttributeValue(string $value): ?string
    {
        $value = trim(preg_replace('/[^\p{L}\p{N} .,_\-\/()+]/u', '', $value) ?? '');
        return $value === '' ? null : mb_substr($value, 0, 48);
    }
}
