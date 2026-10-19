"""Use Raspberry Pi GPIO when available and a harmless no-op backend elsewhere."""

try:
    import RPi.GPIO as GPIO  # type: ignore
    GPIO_AVAILABLE = True
except (ImportError, RuntimeError):
    GPIO_AVAILABLE = False

    class _NoopGPIO:
        BCM = "BCM"
        BOARD = "BOARD"
        IN = "IN"
        OUT = "OUT"
        HIGH = 1
        LOW = 0
        PUD_UP = "PUD_UP"
        PUD_DOWN = "PUD_DOWN"
        RISING = "RISING"
        FALLING = "FALLING"
        BOTH = "BOTH"

        @staticmethod
        def setwarnings(*_args, **_kwargs): return None
        @staticmethod
        def setmode(*_args, **_kwargs): return None
        @staticmethod
        def setup(*_args, **_kwargs): return None
        @staticmethod
        def output(*_args, **_kwargs): return None
        @staticmethod
        def input(*_args, **_kwargs): return 0
        @staticmethod
        def cleanup(*_args, **_kwargs): return None
        @staticmethod
        def add_event_detect(*_args, **_kwargs): return None
        @staticmethod
        def remove_event_detect(*_args, **_kwargs): return None

    GPIO = _NoopGPIO()
