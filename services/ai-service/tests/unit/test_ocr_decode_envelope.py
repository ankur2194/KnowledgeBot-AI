"""`open_guarded`: the decoder allow-list and the two decode caps.

Three separate defences, and each one fails silently if it is merely *present* rather than
correct:

* **`formats=`** must be passed on every open. Pillow's default is to sniff and dispatch to any
  registered plugin, so an upload named `.png` reaches the GD, BDF, PCF, FITS or EPS decoder if
  its magic bytes say so. Every test below that rejects a format first proves Pillow would have
  opened it happily without the argument — otherwise the test passes on a file that was simply
  broken and proves nothing about the allow-list.
* **The pixel cap must fire before a decode.** `Image.open` is header-only, and that laziness is
  the entire reason a 40 M-pixel cap protects anything: checking after `load()` reports a bomb
  that already went off. The bomb fixtures here declare an enormous size and carry an image
  stream that *cannot* decode, so a rejection can only have come from the header.
* **The frame cap must be separate from the pixel cap.** A 50 000-frame GIF of 32x32 tiles is
  1 600 pixels per frame; the pixel cap never sees it.
"""

from __future__ import annotations

import contextlib
import io
import warnings
import zlib
from collections.abc import Iterator
from typing import Any, Final

import pytest
from PIL import Image, UnidentifiedImageError

from app.core.errors import ErrorClass
from app.ingestion.ocr.guarded import (
    DECODERS,
    MAX_FRAMES,
    MAX_PIXELS,
    OcrRejected,
    open_guarded,
)

#: Between `MAX_PIXELS` (40 M) and Pillow's own warn band (~89 M), so the rejection under test is
#: unambiguously ours. A fixture above Pillow's band would pass whether or not this module
#: checked anything.
BOMB_SIDE: Final[int] = 8_000

#: Above Pillow's hard band (~179 M), where `Image.open` itself raises `DecompressionBombError`.
#: That is not an `OSError`, so it escapes the ordinary decode handler unless it is translated.
HUGE_SIDE: Final[int] = 20_000


def _png(width: int, height: int) -> bytes:
    """A one-pixel PNG whose IHDR has been rewritten to declare `width` x `height`.

    The image stream still holds one pixel, so decoding this at the declared size raises
    "image file is truncated". That is exactly the property the pixel-cap tests need: if
    `open_guarded` returns or raises anything other than `OcrRejected`, it decoded.
    """
    buffer = io.BytesIO()
    Image.new("L", (1, 1)).save(buffer, format="PNG")
    raw = bytearray(buffer.getvalue())
    # 8 signature + 4 length + 4 type = 16; IHDR data is 13 bytes, then a CRC over type+data.
    raw[16:20] = width.to_bytes(4, "big")
    raw[20:24] = height.to_bytes(4, "big")
    raw[29:33] = zlib.crc32(bytes(raw[12:29])).to_bytes(4, "big")
    return bytes(raw)


def _real_png(side: int = 4) -> bytes:
    buffer = io.BytesIO()
    Image.new("RGB", (side, side), (255, 255, 255)).save(buffer, format="PNG")
    return buffer.getvalue()


def _ppm() -> bytes:
    """Netpbm: a registered Pillow decoder that is deliberately NOT in `DECODERS`."""
    return b"P6\n2 2\n255\n" + bytes([255, 0, 0] * 4)


def _gif(frames: int) -> bytes:
    buffer = io.BytesIO()
    # Visibly different frames: the GIF writer drops a frame identical to its predecessor, so a
    # fixture built from one repeated colour silently saves as a single frame.
    pages = [
        Image.new("RGB", (8, 8), (index * 23 % 256, index * 7 % 256, 0)) for index in range(frames)
    ]
    pages[0].save(buffer, format="GIF", save_all=True, append_images=pages[1:])
    return buffer.getvalue()


@contextlib.contextmanager
def _quiet_plugin_chatter() -> Iterator[None]:
    """Silence Pillow's `UserWarning` narration of corrupt metadata, and nothing else.

    Scoped to the two tests that feed deliberately broken files: `DecompressionBombWarning` is a
    `RuntimeWarning` and stays visible, because this module treats it as a rejection and a
    blanket filter here would hide the one warning that matters.
    """
    with warnings.catch_warnings():
        warnings.simplefilter("ignore", UserWarning)
        yield


class _CountingBytesIO(io.BytesIO):
    """Records how many bytes were actually pulled off the stream.

    The direct measurement behind "the cap fires before a decode": a rejection that read a few
    hundred bytes of a multi-megabyte stream did not decode anything.
    """

    def __init__(self, data: bytes) -> None:
        super().__init__(data)
        self.bytes_read = 0

    def read(self, size: int | None = -1, /) -> bytes:
        chunk = super().read(size)
        self.bytes_read += len(chunk)
        return chunk


# ── the decoder allow-list ───────────────────────────────────────────────────


def test_a_decoder_outside_the_allow_list_is_refused() -> None:
    """And the second assertion is the one that gives the first any meaning: the same bytes
    open fine when Pillow is left to sniff, so the refusal is the allow-list and not a broken
    file."""
    assert Image.open(io.BytesIO(_ppm())).size == (2, 2)  # sniffing would have taken it

    with pytest.raises(OcrRejected) as caught:
        open_guarded(io.BytesIO(_ppm()))
    assert caught.value.error_class is ErrorClass.PARSING
    assert not caught.value.retryable


def test_the_allow_list_travels_on_the_call_rather_than_being_configured_globally() -> None:
    """`Image.open(fp, formats=...)` per call, never a process-wide plugin de-registration: a
    global would be undone by any library that re-registers on import, and the failure is
    invisible — the upload simply starts opening again."""
    listed = open_guarded(io.BytesIO(_real_png()))
    assert listed.format in DECODERS


@pytest.mark.parametrize("fmt", sorted(DECODERS))
def test_every_listed_format_still_opens(fmt: str) -> None:
    """The allow-list must not be so tight that it refuses what the upload allow-list accepts —
    a decoder guard that rejects everything is indistinguishable from an outage."""
    buffer = io.BytesIO()
    Image.new("RGB", (8, 8), (10, 20, 30)).save(buffer, format=fmt)
    buffer.seek(0)
    assert open_guarded(buffer).format == fmt


def test_bytes_that_are_not_an_image_at_all_are_refused() -> None:
    with pytest.raises(OcrRejected):
        open_guarded(io.BytesIO(b"not an image, just a note"))


# ── the pixel cap, and that it precedes any decode ───────────────────────────


def test_the_pixel_cap_fires_on_a_header_that_cannot_be_decoded() -> None:
    """The proof that the check is on the header. This fixture's image stream holds one pixel
    while its IHDR declares 64 million, so `load()` raises `OSError: image file is truncated`.
    An implementation that decoded before checking could not produce `OcrRejected` here."""
    payload = _png(BOMB_SIDE, BOMB_SIDE)
    assert BOMB_SIDE * BOMB_SIDE > MAX_PIXELS

    lazy = Image.open(io.BytesIO(payload), formats=["PNG"])
    with pytest.raises(OSError, match="truncated"):
        lazy.load()  # any decode of this fixture fails, loudly

    with pytest.raises(OcrRejected) as caught:
        open_guarded(io.BytesIO(payload))
    assert str(BOMB_SIDE * BOMB_SIDE) in str(caught.value)
    assert str(MAX_PIXELS) in str(caught.value)


def test_rejecting_an_oversized_image_reads_only_its_header() -> None:
    """The same property measured rather than inferred. A real 8 000 x 8 000 raster is 64 MB
    decoded; this rejection touches well under a kilobyte of stream."""
    stream = _CountingBytesIO(_png(BOMB_SIDE, BOMB_SIDE) + b"\x00" * 2_000_000)
    with pytest.raises(OcrRejected):
        open_guarded(stream)
    assert stream.bytes_read < 1_024, f"read {stream.bytes_read} bytes to reject a header"


def test_pillows_own_hard_bomb_error_is_translated_rather_than_escaping() -> None:
    """Above Pillow's own band `Image.open` raises `DecompressionBombError`, which subclasses
    `Exception` and not `OSError`. Untranslated it leaves this module as an unclassified
    exception and lands in the taxonomy as an internal defect rather than a rejected upload."""
    payload = _png(HUGE_SIDE, HUGE_SIDE)
    with pytest.raises(Image.DecompressionBombError):
        Image.open(io.BytesIO(payload), formats=["PNG"])

    with pytest.raises(OcrRejected, match="declares a decompression bomb") as caught:
        open_guarded(io.BytesIO(payload))
    assert caught.value.error_class is ErrorClass.PARSING


def test_a_promoted_bomb_warning_is_translated_too() -> None:
    """Worker startup promotes `DecompressionBombWarning` to an exception — a `RuntimeWarning`
    subclass, so it is not an `OSError` either. This is the band between Pillow's warn line and
    its error line, which our own cap already covers; the translation exists so the promotion
    cannot turn a rejected upload into an unhandled exception."""
    side = 9_500  # ~90 M pixels: above Pillow's warn line, below its error line
    payload = _png(side, side)
    with pytest.warns(Image.DecompressionBombWarning):
        Image.open(io.BytesIO(payload), formats=["PNG"])

    # Unpromoted, our own cap answers first and Pillow's warning is incidental noise.
    with pytest.warns(Image.DecompressionBombWarning), pytest.raises(OcrRejected):
        open_guarded(io.BytesIO(payload))

    with warnings.catch_warnings():
        warnings.simplefilter("error", Image.DecompressionBombWarning)
        with pytest.raises(OcrRejected, match="declares a decompression bomb"):
            open_guarded(io.BytesIO(payload))


def test_an_image_exactly_at_the_cap_is_accepted() -> None:
    """The comparison is `>`, not `>=`, and the boundary is tested *on* the boundary. An
    off-by-one here refuses a legitimate document permanently — the same bytes are refused on
    every retry, so there is no recovery short of loosening the cap and reprocessing."""
    width, height = 8_000, MAX_PIXELS // 8_000
    assert width * height == MAX_PIXELS
    assert open_guarded(io.BytesIO(_png(width, height))).size == (width, height)


def test_one_pixel_over_the_cap_is_refused() -> None:
    width, height = 8_000, MAX_PIXELS // 8_000
    with pytest.raises(OcrRejected):
        open_guarded(io.BytesIO(_png(width + 1, height)))


# ── the frame cap ────────────────────────────────────────────────────────────


def test_the_frame_cap_catches_what_the_pixel_cap_cannot() -> None:
    payload = _gif(MAX_FRAMES + 1)
    assert Image.open(io.BytesIO(payload)).size == (8, 8)  # trivially inside the pixel cap

    with pytest.raises(OcrRejected) as caught:
        open_guarded(io.BytesIO(payload))
    assert str(MAX_FRAMES) in str(caught.value)


def test_a_multi_frame_image_within_the_cap_is_accepted() -> None:
    assert open_guarded(io.BytesIO(_gif(MAX_FRAMES))).size == (8, 8)


def test_a_single_frame_image_is_accepted() -> None:
    """`getattr(..., "n_frames", 1)` rather than an attribute access. Pillow 12 does put the
    attribute on every `ImageFile`, but the default is what keeps this from rejecting an image
    class that predates it or a wrapper that does not forward it — and an `AttributeError` here
    would refuse every ordinary upload."""
    image = open_guarded(io.BytesIO(_real_png()))
    assert image.n_frames == 1


def test_an_unreadable_frame_table_is_a_rejection_not_a_crash() -> None:
    """A truncated GIF cannot be bounded, and an image we cannot bound is one we refuse rather
    than one we assume holds a single frame.

    The exception the frame scan raises here is `IndexError`, which is why the handler is not
    the `OSError` family: a narrow catch would let this leave as an unclassified exception and
    be recorded as an internal defect — retried three times against bytes that will never
    change — instead of as a rejected upload."""
    truncated = _gif(9)[:120]
    with pytest.raises(IndexError):
        _ = Image.open(io.BytesIO(truncated), formats=["GIF"]).n_frames

    with pytest.raises(OcrRejected) as caught:
        open_guarded(io.BytesIO(truncated))
    assert caught.value.error_class is ErrorClass.PARSING


def test_a_corrupt_tiff_frame_table_is_a_rejection_too() -> None:
    """The second measured escape: `TypeError("Missing dimensions")` out of the TIFF frame
    scanner. Two different non-`OSError` types from two plugins is the evidence the handler is
    broad on purpose rather than by habit."""
    buffer = io.BytesIO()
    pages = [Image.new("RGB", (32, 32), (9, 9, 9)), Image.new("RGB", (32, 32), (200, 9, 9))]
    pages[0].save(buffer, format="TIFF", save_all=True, append_images=pages[1:])
    # Half a two-page TIFF: the first IFD is intact, so the header parses and `size` is real,
    # while the second IFD has lost its ImageWidth tag and the frame scan cannot reach it.
    truncated = buffer.getvalue()[: len(buffer.getvalue()) // 2]

    # Pillow's TIFF plugin narrates corrupt metadata through `UserWarning`. Silenced here only:
    # a hostile upload producing warnings is expected, and letting them through would bury the
    # `DecompressionBombWarning` that this module treats as a rejection.
    with _quiet_plugin_chatter():
        with pytest.raises(TypeError, match="Missing dimensions"):
            _ = Image.open(io.BytesIO(truncated), formats=["TIFF"]).n_frames

        with pytest.raises(OcrRejected):
            open_guarded(io.BytesIO(truncated))


def test_no_malformed_upload_escapes_as_an_unclassified_exception() -> None:
    """The fuzz the broad handler was written from, kept as a regression.

    Deterministic seed, every allowed format, bit flips plus truncation. Before the frame-table
    handler was widened this failed on 3 of 1 800 samples — all of them `n_frames`, none of them
    `Image.open`. Every escape here is an upload that would be retried three times and then
    recorded as a defect in this service rather than as a file we refuse."""
    import random

    rng = random.Random(7)  # noqa: S311 - fixture generation, not a security decision
    samples = 0
    with _quiet_plugin_chatter():
        for fmt in sorted(DECODERS):
            buffer = io.BytesIO()
            Image.new("RGB", (32, 32), (9, 9, 9)).save(buffer, format=fmt)
            clean = buffer.getvalue()
            for _ in range(300):
                mutated = bytearray(clean)
                for _ in range(3):
                    mutated[rng.randrange(len(mutated))] = rng.randrange(256)
                payload = bytes(mutated[: rng.randrange(4, len(mutated))])
                samples += 1
                with contextlib.suppress(OcrRejected):
                    open_guarded(io.BytesIO(payload))
    assert samples == 300 * len(DECODERS)


# ── the returned object ──────────────────────────────────────────────────────


def test_the_returned_image_is_still_lazy() -> None:
    """The caller stamps DPI and applies preprocessing before anything is decoded; returning an
    already-loaded image would move the decode inside the guard, where its cost is unbudgeted."""
    image = open_guarded(io.BytesIO(_real_png(64)))
    assert getattr(image, "_im", None) is None
    image.load()
    assert image.size == (64, 64)


def test_unidentified_and_os_errors_both_arrive_as_the_same_rejection() -> None:
    """`UnidentifiedImageError` subclasses `OSError`, so listing both is redundant *today*;
    it is listed because the two mean different things and a future Pillow narrowing the base
    class would otherwise turn one of them into an unhandled exception."""
    assert issubclass(UnidentifiedImageError, OSError)

    class _Exploding(io.BytesIO):
        def read(self, size: int | None = -1, /) -> bytes:
            raise OSError("simulated storage read failure")

    with pytest.raises(OcrRejected):
        open_guarded(_Exploding(_real_png()))


def test_open_guarded_never_raises_a_bare_exception_on_any_fixture() -> None:
    """Every rejection path in this module is `parsing`, permanent. A path that escaped as a
    bare exception would be classified as an internal defect and retried three times."""
    fixtures: list[Any] = [
        io.BytesIO(b""),
        io.BytesIO(_ppm()),
        io.BytesIO(_png(BOMB_SIDE, BOMB_SIDE)),
        io.BytesIO(_png(HUGE_SIDE, HUGE_SIDE)),
        io.BytesIO(_gif(MAX_FRAMES + 1)),
    ]
    for fixture in fixtures:
        with pytest.raises(OcrRejected):
            open_guarded(fixture)
