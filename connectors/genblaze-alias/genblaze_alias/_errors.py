"""Map Alias / httpx failures to a Genblaze :class:`ProviderErrorCode`.

The codes drive Genblaze's retry policy: TIMEOUT / RATE_LIMIT / SERVER_ERROR
are retried; AUTH_FAILURE / INVALID_INPUT / CONTENT_POLICY are not. Alias
already does its own 429/GPU-fault backoff internally, but surfacing the right
code lets the pipeline layer make sensible decisions too.
"""

from __future__ import annotations

import httpx

from genblaze_core.models.enums import ProviderErrorCode


def classify_status(status: int) -> ProviderErrorCode:
    """Classify an HTTP status code from the Alias API."""
    if status == 429:
        return ProviderErrorCode.RATE_LIMIT
    if status in (401, 403):
        return ProviderErrorCode.AUTH_FAILURE
    if status == 408:
        return ProviderErrorCode.TIMEOUT
    if 400 <= status < 500:
        return ProviderErrorCode.INVALID_INPUT
    if status >= 500:
        return ProviderErrorCode.SERVER_ERROR
    return ProviderErrorCode.UNKNOWN


def classify_exception(exc: Exception) -> ProviderErrorCode:
    """Classify an httpx transport/status exception."""
    if isinstance(exc, httpx.TimeoutException):
        return ProviderErrorCode.TIMEOUT
    if isinstance(exc, httpx.HTTPStatusError):
        return classify_status(exc.response.status_code)
    if isinstance(exc, httpx.TransportError):
        return ProviderErrorCode.SERVER_ERROR
    return ProviderErrorCode.UNKNOWN
