#!/usr/bin/env python3
"""Private loopback-only speech service for RailTime and Followflow.

The service intentionally uses only Python's standard library.  It delegates
audio conversion, transcription and synthesis to the same ffmpeg,
whisper.cpp and Piper CLI contracts that Followflow already uses.
"""

from __future__ import annotations

import argparse
import base64
import binascii
import getpass
import hashlib
import hmac
import http.server
import json
import logging
import math
import os
import re
import shutil
import signal
import socket
import stat
import subprocess
import sys
import tempfile
import threading
import time
import uuid
import wave
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Mapping, Optional, Sequence, Set, Tuple
from urllib.parse import urlsplit


BIND_HOST = "127.0.0.1"
DEFAULT_PORT = 8092
CONFIG_ENV = "LMZ_SPEECH_CONFIG"
DUMMY_TOKEN_HASH = "0" * 64
REQUEST_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
CLIENT_ID_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
FILENAME_PATTERN = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")
LANGUAGE_PATTERN = re.compile(r"^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$")
TOKEN_HASH_PATTERN = re.compile(r"^[a-fA-F0-9]{64}$")
ANSI_PATTERN = re.compile(r"\x1B(?:[@-Z\\-_]|\[[0-?]*[ -/]*[@-~])")
TIMESTAMP_PATTERN = re.compile(r"^\[[\d:.]+\s+-->\s+[\d:.]+\]\s*")
SUPPORTED_MIME_TYPES = {
    "audio/webm": ".webm",
    "video/webm": ".webm",
    "audio/ogg": ".ogg",
    "audio/wav": ".wav",
    "audio/x-wav": ".wav",
    "audio/wave": ".wav",
    "audio/mpeg": ".mp3",
    "audio/mp4": ".m4a",
    "video/mp4": ".m4a",
    "audio/x-m4a": ".m4a",
}


LOGGER = logging.getLogger("lmz_speech_service")


class ConfigurationError(ValueError):
    """Raised when the trusted service configuration is invalid."""


class ServiceError(Exception):
    """Safe, public error that never contains subprocess or request data."""

    def __init__(
        self,
        status: int,
        code: str,
        message: str,
        headers: Optional[Mapping[str, str]] = None,
    ) -> None:
        super().__init__(message)
        self.status = status
        self.code = code
        self.message = message
        self.headers = dict(headers or {})


class ProcessTimeoutError(Exception):
    pass


class ProcessExecutionError(Exception):
    pass


@dataclass(frozen=True)
class Limits:
    max_body_bytes: int
    max_audio_bytes: int
    max_audio_seconds: float
    max_text_chars: int
    max_transcript_chars: int
    max_output_audio_bytes: int
    request_read_timeout_seconds: float


@dataclass(frozen=True)
class Concurrency:
    whisper: int
    piper: int
    http_threads: int
    queue_wait_seconds: float


@dataclass(frozen=True)
class CommandConfig:
    command: Tuple[str, ...]
    timeout_seconds: float


@dataclass(frozen=True)
class WhisperConfig(CommandConfig):
    model: Path
    language: str
    threads: int


@dataclass(frozen=True)
class PiperConfig(CommandConfig):
    model: Path
    config: Path
    mode: str


@dataclass(frozen=True)
class EngineConfig:
    ffmpeg: CommandConfig
    whisper: WhisperConfig
    piper: PiperConfig


@dataclass(frozen=True)
class ServiceConfig:
    port: int
    clients: Mapping[str, str]
    engines: EngineConfig
    limits: Limits
    concurrency: Concurrency
    temp_dir: Path

    @classmethod
    def from_mapping(cls, raw: Mapping[str, Any]) -> "ServiceConfig":
        root = _mapping(raw, "configuration")
        server = _mapping(root.get("server", {}), "server")
        port = _integer(server, "port", DEFAULT_PORT, 1, 65535)

        clients_raw = _mapping(root.get("clients"), "clients")
        if not clients_raw:
            raise ConfigurationError("clients must contain at least one client")
        clients: Dict[str, str] = {}
        for client_id, entry in clients_raw.items():
            if not isinstance(client_id, str) or not CLIENT_ID_PATTERN.fullmatch(client_id):
                raise ConfigurationError("client IDs must match the documented safe pattern")
            client = _mapping(entry, "clients.%s" % client_id)
            if "token" in client:
                raise ConfigurationError("plaintext client tokens are forbidden")
            token_hash = client.get("token_sha256")
            if not isinstance(token_hash, str) or not TOKEN_HASH_PATTERN.fullmatch(token_hash):
                raise ConfigurationError(
                    "clients.%s.token_sha256 must be a 64-character SHA-256 hex digest" % client_id
                )
            normalized_token_hash = token_hash.lower()
            if normalized_token_hash in clients.values():
                raise ConfigurationError("each client must use a distinct token hash")
            clients[client_id] = normalized_token_hash

        engines_raw = _mapping(root.get("engines"), "engines")
        ffmpeg_raw = _mapping(engines_raw.get("ffmpeg"), "engines.ffmpeg")
        whisper_raw = _mapping(engines_raw.get("whisper"), "engines.whisper")
        piper_raw = _mapping(engines_raw.get("piper"), "engines.piper")

        ffmpeg = CommandConfig(
            command=_command(ffmpeg_raw, "engines.ffmpeg.command"),
            timeout_seconds=_number(ffmpeg_raw, "timeout_seconds", 60.0, 5.0, 900.0),
        )
        whisper = WhisperConfig(
            command=_command(whisper_raw, "engines.whisper.command"),
            timeout_seconds=_number(whisper_raw, "timeout_seconds", 240.0, 5.0, 900.0),
            model=_absolute_path(whisper_raw.get("model"), "engines.whisper.model"),
            language=_language(whisper_raw.get("language", "de"), "engines.whisper.language"),
            threads=_integer(whisper_raw, "threads", 0, 0, 256),
        )
        piper_model = _absolute_path(piper_raw.get("model"), "engines.piper.model")
        piper_config_value = piper_raw.get("config", str(piper_model) + ".json")
        piper_mode = piper_raw.get("mode", "cli")
        if piper_mode not in ("cli", "legacy"):
            raise ConfigurationError("engines.piper.mode must be cli or legacy")
        piper = PiperConfig(
            command=_command(piper_raw, "engines.piper.command"),
            timeout_seconds=_number(piper_raw, "timeout_seconds", 120.0, 5.0, 900.0),
            model=piper_model,
            config=_absolute_path(piper_config_value, "engines.piper.config"),
            mode=piper_mode,
        )

        limits_raw = _mapping(root.get("limits", {}), "limits")
        limits = Limits(
            max_body_bytes=_integer(limits_raw, "max_body_bytes", 29_000_000, 1024, 100_000_000),
            max_audio_bytes=_integer(limits_raw, "max_audio_bytes", 20_971_520, 1, 75_000_000),
            max_audio_seconds=_number(limits_raw, "max_audio_seconds", 60.0, 1.0, 900.0),
            max_text_chars=_integer(limits_raw, "max_text_chars", 4000, 1, 50_000),
            max_transcript_chars=_integer(limits_raw, "max_transcript_chars", 8000, 1, 50_000),
            max_output_audio_bytes=_integer(
                limits_raw, "max_output_audio_bytes", 33_554_432, 1024, 100_000_000
            ),
            request_read_timeout_seconds=_number(
                limits_raw, "request_read_timeout_seconds", 15.0, 1.0, 120.0
            ),
        )
        minimum_body_size = ((limits.max_audio_bytes + 2) // 3) * 4 + 1024
        if limits.max_body_bytes < minimum_body_size:
            raise ConfigurationError(
                "limits.max_body_bytes must accommodate base64 max_audio_bytes plus JSON overhead"
            )

        concurrency_raw = _mapping(root.get("concurrency", {}), "concurrency")
        concurrency = Concurrency(
            whisper=_integer(concurrency_raw, "whisper", 1, 1, 32),
            piper=_integer(concurrency_raw, "piper", 1, 1, 32),
            http_threads=_integer(concurrency_raw, "http_threads", 8, 2, 64),
            queue_wait_seconds=_number(concurrency_raw, "queue_wait_seconds", 3.0, 0.0, 30.0),
        )

        runtime = _mapping(root.get("runtime"), "runtime")
        temp_dir = _absolute_path(runtime.get("temp_dir"), "runtime.temp_dir")

        return cls(
            port=port,
            clients=clients,
            engines=EngineConfig(ffmpeg=ffmpeg, whisper=whisper, piper=piper),
            limits=limits,
            concurrency=concurrency,
            temp_dir=temp_dir,
        )


def _mapping(value: Any, name: str) -> Mapping[str, Any]:
    if not isinstance(value, Mapping):
        raise ConfigurationError("%s must be a JSON object" % name)
    return value


def _integer(
    values: Mapping[str, Any], key: str, default: int, minimum: int, maximum: int
) -> int:
    value = values.get(key, default)
    if isinstance(value, bool) or not isinstance(value, int):
        raise ConfigurationError("%s must be an integer" % key)
    if value < minimum or value > maximum:
        raise ConfigurationError("%s is outside the allowed range" % key)
    return value


def _number(
    values: Mapping[str, Any], key: str, default: float, minimum: float, maximum: float
) -> float:
    value = values.get(key, default)
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise ConfigurationError("%s must be numeric" % key)
    result = float(value)
    if not math.isfinite(result) or result < minimum or result > maximum:
        raise ConfigurationError("%s is outside the allowed range" % key)
    return result


def _command(values: Mapping[str, Any], name: str) -> Tuple[str, ...]:
    command = values.get("command")
    if not isinstance(command, list) or not command:
        raise ConfigurationError("%s must be a non-empty JSON array" % name)
    result: List[str] = []
    for part in command:
        if not isinstance(part, str) or not part.strip() or "\x00" in part:
            raise ConfigurationError("%s contains an invalid argument" % name)
        result.append(part.strip())
    return tuple(result)


def _absolute_path(value: Any, name: str) -> Path:
    if not isinstance(value, str) or not value.strip():
        raise ConfigurationError("%s must be a non-empty absolute path" % name)
    path = Path(value.strip())
    if not path.is_absolute():
        raise ConfigurationError("%s must be an absolute path" % name)
    return path


def _language(value: Any, name: str) -> str:
    if not isinstance(value, str) or not LANGUAGE_PATTERN.fullmatch(value):
        raise ConfigurationError("%s must be a valid language tag" % name)
    return value


def _assert_secure_file(path: Path, label: str) -> None:
    if not path.is_file():
        raise ConfigurationError("%s file does not exist" % label)
    if os.name == "posix":
        mode = path.stat().st_mode
        if mode & (stat.S_IWGRP | stat.S_IWOTH):
            raise ConfigurationError("%s file must not be group/world writable" % label)


def load_env_file(path: Path) -> None:
    """Load only LMZ_SPEECH_CONFIG from an explicit, integrity-protected env file."""

    if not path.is_absolute():
        raise ConfigurationError("the env file path must be absolute")
    _assert_secure_file(path, "environment")
    configured_value: Optional[str] = None
    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].lstrip()
        if "=" not in line:
            raise ConfigurationError("invalid environment line %d" % line_number)
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if key != CONFIG_ENV:
            raise ConfigurationError("only %s is allowed in the service env file" % CONFIG_ENV)
        if (value.startswith('"') and value.endswith('"')) or (
            value.startswith("'") and value.endswith("'")
        ):
            value = value[1:-1]
        if not value:
            raise ConfigurationError("%s must not be empty" % CONFIG_ENV)
        if configured_value is not None:
            raise ConfigurationError("%s occurs more than once in the env file" % CONFIG_ENV)
        configured_value = value
    if configured_value is None:
        raise ConfigurationError("the env file does not define %s" % CONFIG_ENV)
    os.environ[CONFIG_ENV] = configured_value


def load_config_from_environment() -> ServiceConfig:
    configured = os.environ.get(CONFIG_ENV, "").strip()
    if not configured:
        raise ConfigurationError("%s must point to the service JSON configuration" % CONFIG_ENV)
    config_path = Path(configured)
    if not config_path.is_absolute():
        raise ConfigurationError("%s must contain an absolute path" % CONFIG_ENV)
    _assert_secure_file(config_path, "configuration")
    try:
        raw = json.loads(config_path.read_text(encoding="utf-8"))
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise ConfigurationError("the service configuration is not valid UTF-8 JSON") from exc
    return ServiceConfig.from_mapping(_mapping(raw, "configuration"))


def token_sha256(token: str) -> str:
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


class ProcessRunner:
    def __init__(self) -> None:
        self._active: Set[subprocess.Popen] = set()
        self._lock = threading.Lock()
        self._closed = False

    def run(
        self,
        command: Sequence[str],
        timeout_seconds: float,
        cwd: Path,
        stdin: Optional[bytes] = None,
    ) -> bytes:
        try:
            with self._lock:
                if self._closed:
                    raise ProcessExecutionError()
                process = subprocess.Popen(
                    list(command),
                    stdin=subprocess.PIPE if stdin is not None else subprocess.DEVNULL,
                    stdout=subprocess.PIPE,
                    stderr=subprocess.PIPE,
                    cwd=str(cwd),
                    shell=False,
                    start_new_session=(os.name == "posix"),
                )
                self._active.add(process)
        except OSError as exc:
            raise ProcessExecutionError() from exc

        try:
            stdout, _stderr = process.communicate(input=stdin, timeout=timeout_seconds)
        except subprocess.TimeoutExpired as exc:
            self._terminate_and_wait(process)
            raise ProcessTimeoutError() from exc
        finally:
            with self._lock:
                self._active.discard(process)

        if process.returncode != 0:
            # Subprocess output can contain request data or filesystem paths and
            # is deliberately neither logged nor exposed through the API.
            raise ProcessExecutionError()
        return stdout

    def terminate_all(self) -> None:
        with self._lock:
            self._closed = True
            active = list(self._active)

        for process in active:
            self._signal_process_group(process, signal.SIGTERM)

        deadline = time.monotonic() + 2.0
        for process in active:
            remaining = max(0.0, deadline - time.monotonic())
            try:
                process.wait(timeout=remaining)
            except subprocess.TimeoutExpired:
                self._signal_process_group(process, getattr(signal, "SIGKILL", 9))

    def _terminate_and_wait(self, process: subprocess.Popen) -> None:
        self._signal_process_group(process, signal.SIGTERM)
        try:
            process.communicate(timeout=2.0)
        except subprocess.TimeoutExpired:
            self._signal_process_group(process, getattr(signal, "SIGKILL", 9))
            process.communicate()

    @staticmethod
    def _signal_process_group(process: subprocess.Popen, signum: int) -> None:
        try:
            if os.name == "posix":
                os.killpg(process.pid, signum)
            elif signum == signal.SIGTERM:
                process.terminate()
            else:
                process.kill()
        except (OSError, ProcessLookupError):
            pass


class SpeechEngines:
    def __init__(self, config: ServiceConfig, runner: Optional[ProcessRunner] = None) -> None:
        self.config = config
        self.runner = runner or ProcessRunner()
        self._prepare_temp_root()

    def _prepare_temp_root(self) -> None:
        path = self.config.temp_dir
        if path.exists() and (not path.is_dir() or path.is_symlink()):
            raise ConfigurationError("runtime.temp_dir must be a real directory")
        path.mkdir(mode=0o700, parents=True, exist_ok=True)

    def shutdown(self) -> None:
        terminate_all = getattr(self.runner, "terminate_all", None)
        if callable(terminate_all):
            terminate_all()

    def readiness(self) -> Mapping[str, str]:
        ffmpeg_ready = _command_available(self.config.engines.ffmpeg.command)
        whisper_ready = (
            _command_available(self.config.engines.whisper.command)
            and _readable_file(self.config.engines.whisper.model)
        )
        piper_ready = (
            _command_available(self.config.engines.piper.command)
            and _readable_file(self.config.engines.piper.model)
            and _readable_file(self.config.engines.piper.config)
        )
        return {
            "ffmpeg": "ready" if ffmpeg_ready else "unavailable",
            "whisper": "ready" if whisper_ready else "unavailable",
            "piper": "ready" if piper_ready else "unavailable",
        }

    def transcribe(
        self,
        audio: bytes,
        extension: str,
        language: str,
        max_audio_seconds: float,
    ) -> str:
        cfg = self.config.engines
        readiness = self.readiness()
        if readiness["ffmpeg"] != "ready" or readiness["whisper"] != "ready":
            raise ServiceError(503, "transcription_unavailable", "Transcription is unavailable.")

        with tempfile.TemporaryDirectory(prefix="stt-", dir=str(self.config.temp_dir)) as temporary:
            directory = Path(temporary)
            os.chmod(str(directory), 0o700)
            input_path = directory / ("input" + extension)
            wav_path = directory / "input-16khz.wav"
            input_path.write_bytes(audio)
            os.chmod(str(input_path), 0o600)

            ffmpeg_command = list(cfg.ffmpeg.command) + [
                "-hide_banner",
                "-loglevel",
                "error",
                "-nostdin",
                "-y",
                "-i",
                str(input_path),
                "-ac",
                "1",
                "-ar",
                "16000",
                "-c:a",
                "pcm_s16le",
                "-t",
                "%.3f" % (max_audio_seconds + 0.25),
                str(wav_path),
            ]
            self._run(ffmpeg_command, cfg.ffmpeg.timeout_seconds, directory, "ffmpeg")
            if not _readable_file(wav_path) or wav_path.stat().st_size <= 44:
                raise ServiceError(422, "invalid_audio", "The audio could not be decoded.")
            if _wav_duration_seconds(wav_path) > max_audio_seconds:
                raise ServiceError(413, "audio_duration_exceeded", "The audio duration limit was exceeded.")

            whisper_language = language.split("-", 1)[0].lower()
            whisper_command = list(cfg.whisper.command) + [
                "-m",
                str(cfg.whisper.model),
                "-f",
                str(wav_path),
                "-nt",
                "-l",
                whisper_language,
            ]
            if cfg.whisper.threads > 0:
                whisper_command.extend(["-t", str(cfg.whisper.threads)])
            output = self._run(
                whisper_command,
                cfg.whisper.timeout_seconds,
                directory,
                "whisper",
            )
            transcript = normalize_transcript(output.decode("utf-8", errors="replace"))
            if not transcript:
                raise ServiceError(422, "empty_transcript", "No speech was detected.")
            return transcript

    def synthesize(self, text: str, speed: float) -> bytes:
        cfg = self.config.engines.piper
        if self.readiness()["piper"] != "ready":
            raise ServiceError(503, "synthesis_unavailable", "Speech synthesis is unavailable.")

        with tempfile.TemporaryDirectory(prefix="tts-", dir=str(self.config.temp_dir)) as temporary:
            directory = Path(temporary)
            os.chmod(str(directory), 0o700)
            text_path = directory / "speech.txt"
            output_path = directory / "speech.wav"
            text_path.write_text(text + "\n", encoding="utf-8")
            os.chmod(str(text_path), 0o600)

            length_scale = "%.4f" % (1.0 / speed)
            command = list(cfg.command) + [
                "--model",
                str(cfg.model),
                "--config",
                str(cfg.config),
            ]
            stdin: Optional[bytes] = None
            if cfg.mode == "legacy":
                command.extend(["--length_scale", length_scale, "--output_file", str(output_path)])
                stdin = (text + "\n").encode("utf-8")
            else:
                command.extend(
                    [
                        "--length-scale",
                        length_scale,
                        "--input-file",
                        str(text_path),
                        "--output-file",
                        str(output_path),
                    ]
                )
            self._run(command, cfg.timeout_seconds, directory, "piper", stdin)
            if not _readable_file(output_path):
                raise ServiceError(503, "synthesis_failed", "Speech synthesis failed.")
            if output_path.stat().st_size > self.config.limits.max_output_audio_bytes:
                raise ServiceError(503, "speech_output_too_large", "Speech synthesis output is invalid.")
            audio = output_path.read_bytes()
            if len(audio) <= 44 or not audio.startswith(b"RIFF") or audio[8:12] != b"WAVE":
                raise ServiceError(503, "invalid_speech_output", "Speech synthesis returned invalid audio.")
            return audio

    def _run(
        self,
        command: Sequence[str],
        timeout_seconds: float,
        cwd: Path,
        engine: str,
        stdin: Optional[bytes] = None,
    ) -> bytes:
        try:
            return self.runner.run(command, timeout_seconds, cwd, stdin)
        except ProcessTimeoutError as exc:
            raise ServiceError(504, engine + "_timeout", "The speech engine timed out.") from exc
        except ProcessExecutionError as exc:
            raise ServiceError(503, engine + "_failed", "The speech engine failed.") from exc


def _command_available(command: Sequence[str]) -> bool:
    if not command:
        return False
    binary = command[0]
    path = Path(binary)
    if path.is_absolute():
        return path.is_file() and os.access(str(path), os.X_OK)
    return shutil.which(binary) is not None


def _readable_file(path: Path) -> bool:
    return path.is_file() and path.stat().st_size > 0 and os.access(str(path), os.R_OK)


def _wav_duration_seconds(path: Path) -> float:
    try:
        with wave.open(str(path), "rb") as wav_file:
            frame_rate = wav_file.getframerate()
            if frame_rate <= 0:
                raise wave.Error("invalid frame rate")
            return wav_file.getnframes() / float(frame_rate)
    except (OSError, EOFError, wave.Error) as exc:
        raise ServiceError(422, "invalid_audio", "The converted audio is invalid.") from exc


def normalize_transcript(output: str) -> str:
    output = ANSI_PATTERN.sub("", output)
    parts: List[str] = []
    for raw_line in output.splitlines():
        line = TIMESTAMP_PATTERN.sub("", raw_line.strip()).strip()
        if not line or (line.startswith("[") and line.endswith("]")):
            continue
        parts.append(line)
    return re.sub(r"\s+", " ", " ".join(parts), flags=re.UNICODE).strip()


class SpeechApplication:
    def __init__(self, config: ServiceConfig, engines: Any) -> None:
        self.config = config
        self.engines = engines
        self._whisper_slots = threading.BoundedSemaphore(config.concurrency.whisper)
        self._piper_slots = threading.BoundedSemaphore(config.concurrency.piper)
        self._stopping = threading.Event()

    def shutdown(self) -> None:
        self._stopping.set()
        shutdown = getattr(self.engines, "shutdown", None)
        if callable(shutdown):
            shutdown()

    def authenticate(self, client_id: str, token: str) -> bool:
        safe_client_id = client_id if CLIENT_ID_PATTERN.fullmatch(client_id or "") else ""
        expected = self.config.clients.get(safe_client_id, DUMMY_TOKEN_HASH)
        supplied = token_sha256(token if len(token) <= 512 else "")
        digest_matches = hmac.compare_digest(supplied, expected)
        return bool(safe_client_id in self.config.clients and token and digest_matches)

    def status(self, request_id: str) -> Mapping[str, Any]:
        engines = dict(self.engines.readiness())
        ready = all(value == "ready" for value in engines.values())
        return {
            "status": "ready" if ready else "degraded",
            "engines": engines,
            "limits": {
                "max_body_bytes": self.config.limits.max_body_bytes,
                "max_audio_bytes": self.config.limits.max_audio_bytes,
                "max_audio_seconds": self.config.limits.max_audio_seconds,
                "max_text_chars": self.config.limits.max_text_chars,
                "max_transcript_chars": self.config.limits.max_transcript_chars,
                "max_output_audio_bytes": self.config.limits.max_output_audio_bytes,
            },
            "concurrency": {
                "whisper": self.config.concurrency.whisper,
                "piper": self.config.concurrency.piper,
                "http_threads": self.config.concurrency.http_threads,
                "queue_wait_seconds": self.config.concurrency.queue_wait_seconds,
            },
            "request_id": request_id,
        }

    def transcribe(self, audio: bytes, extension: str, language: str) -> str:
        if self._stopping.is_set():
            raise ServiceError(503, "service_stopping", "Speech service is stopping.")
        acquired = self._whisper_slots.acquire(timeout=self.config.concurrency.queue_wait_seconds)
        if not acquired:
            raise ServiceError(
                503,
                "transcription_busy",
                "Transcription capacity is busy.",
                {"Retry-After": "1"},
            )
        try:
            text = self.engines.transcribe(
                audio,
                extension,
                language,
                self.config.limits.max_audio_seconds,
            )
        finally:
            self._whisper_slots.release()
        if not isinstance(text, str) or not text.strip():
            raise ServiceError(422, "empty_transcript", "No speech was detected.")
        return text.strip()[: self.config.limits.max_transcript_chars]

    def synthesize(self, text: str, speed: float) -> bytes:
        if self._stopping.is_set():
            raise ServiceError(503, "service_stopping", "Speech service is stopping.")
        acquired = self._piper_slots.acquire(timeout=self.config.concurrency.queue_wait_seconds)
        if not acquired:
            raise ServiceError(
                503,
                "synthesis_busy",
                "Speech synthesis capacity is busy.",
                {"Retry-After": "1"},
            )
        try:
            audio = self.engines.synthesize(text, speed)
        finally:
            self._piper_slots.release()
        if not isinstance(audio, bytes) or len(audio) > self.config.limits.max_output_audio_bytes:
            raise ServiceError(503, "speech_output_too_large", "Speech synthesis output is invalid.")
        return audio


class SpeechHTTPServer(http.server.ThreadingHTTPServer):
    daemon_threads = True
    allow_reuse_address = True

    def __init__(self, address: Tuple[str, int], app: SpeechApplication) -> None:
        if address[0] != BIND_HOST:
            raise ValueError("the speech service may only bind to 127.0.0.1")
        self.app = app
        self._request_slots = threading.BoundedSemaphore(app.config.concurrency.http_threads)
        super().__init__(address, SpeechRequestHandler)

    def process_request(self, request: Any, client_address: Any) -> None:
        if not self._request_slots.acquire(blocking=False):
            self._reject_over_capacity(request)
            return

        try:
            super().process_request(request, client_address)
        except BaseException:
            self._request_slots.release()
            raise

    def process_request_thread(self, request: Any, client_address: Any) -> None:
        try:
            super().process_request_thread(request, client_address)
        finally:
            self._request_slots.release()

    def _reject_over_capacity(self, request: Any) -> None:
        request_id = str(uuid.uuid4())
        body = json.dumps(
            {
                "error": {
                    "code": "server_busy",
                    "message": "Speech service capacity is busy.",
                },
                "request_id": request_id,
            },
            separators=(",", ":"),
        ).encode("utf-8")
        headers = (
            "HTTP/1.1 503 Service Unavailable\r\n"
            "Content-Type: application/json; charset=utf-8\r\n"
            "Cache-Control: no-store\r\n"
            "X-Content-Type-Options: nosniff\r\n"
            "X-Request-ID: %s\r\n"
            "Retry-After: 1\r\n"
            "Connection: close\r\n"
            "Content-Length: %d\r\n\r\n" % (request_id, len(body))
        ).encode("ascii")
        try:
            request.sendall(headers + body)
        except OSError:
            pass
        finally:
            self.shutdown_request(request)
        LOGGER.warning("request_rejected code=server_busy request_id=%s", request_id)

    def handle_error(self, request: Any, client_address: Any) -> None:
        # Avoid BaseServer's traceback, which can include filesystem paths.
        LOGGER.error("request_failed code=uncaught_server_error")


class SpeechRequestHandler(http.server.BaseHTTPRequestHandler):
    protocol_version = "HTTP/1.1"
    server_version = "LMZSpeech"
    sys_version = ""

    @property
    def app(self) -> SpeechApplication:
        return self.server.app  # type: ignore[attr-defined]

    def setup(self) -> None:
        super().setup()
        self.connection.settimeout(self.app.config.limits.request_read_timeout_seconds)

    def log_message(self, format_string: str, *args: Any) -> None:
        # All logging is emitted explicitly with a correlation ID and without
        # headers, request bodies, query strings, transcripts or text.
        return

    def do_GET(self) -> None:
        self._dispatch("GET")

    def do_POST(self) -> None:
        self._dispatch("POST")

    def do_PUT(self) -> None:
        self._method_not_allowed()

    def do_PATCH(self) -> None:
        self._method_not_allowed()

    def do_DELETE(self) -> None:
        self._method_not_allowed()

    def do_OPTIONS(self) -> None:
        self._method_not_allowed()

    def _dispatch(self, method: str) -> None:
        started = time.monotonic()
        request_id = self._request_id()
        route = self._route()
        status = 500
        try:
            if method == "GET" and route == "/healthz":
                status = 200
                self._write_json(200, {"status": "ok"}, request_id)
                return
            if route not in ("/healthz", "/v1/status", "/v1/transcriptions", "/v1/speech"):
                raise ServiceError(404, "not_found", "The requested endpoint was not found.")
            if route != "/healthz":
                self._require_authentication()
            if method == "GET" and route == "/v1/status":
                status = 200
                self._write_json(200, self.app.status(request_id), request_id)
                return
            if method == "POST" and route == "/v1/transcriptions":
                payload = self._read_json()
                audio, extension, language = self._validate_transcription(payload)
                text = self.app.transcribe(audio, extension, language)
                status = 200
                self._write_json(200, {"text": text, "request_id": request_id}, request_id)
                return
            if method == "POST" and route == "/v1/speech":
                payload = self._read_json()
                text, speed = self._validate_speech(payload)
                audio = self.app.synthesize(text, speed)
                status = 200
                self._write_audio(audio, request_id)
                return
            raise ServiceError(
                405,
                "method_not_allowed",
                "The HTTP method is not allowed for this endpoint.",
                {"Allow": self._allowed_methods(route)},
            )
        except ServiceError as exc:
            status = exc.status
            self._write_error(exc, request_id)
        except (BrokenPipeError, ConnectionResetError, socket.timeout):
            status = 499
        except Exception:
            status = 500
            self._write_error(
                ServiceError(500, "internal_error", "The speech service could not process the request."),
                request_id,
            )
        finally:
            elapsed_ms = int((time.monotonic() - started) * 1000)
            LOGGER.info(
                "request method=%s endpoint=%s status=%d request_id=%s elapsed_ms=%d",
                method,
                route if route in ("/healthz", "/v1/status", "/v1/transcriptions", "/v1/speech") else "unknown",
                status,
                request_id,
                elapsed_ms,
            )

    def _route(self) -> str:
        try:
            parsed = urlsplit(self.path)
        except ValueError:
            return ""
        if parsed.query or parsed.fragment:
            return ""
        return parsed.path

    def _request_id(self) -> str:
        supplied = self.headers.get("X-Request-ID", "").strip()
        if REQUEST_ID_PATTERN.fullmatch(supplied):
            return supplied
        return str(uuid.uuid4())

    def _require_authentication(self) -> None:
        client_id = self.headers.get("X-Client-ID", "").strip()
        authorization = self.headers.get("Authorization", "")
        scheme, separator, token = authorization.partition(" ")
        valid_bearer = separator == " " and scheme.lower() == "bearer" and bool(token)
        if not valid_bearer:
            token = ""
        if not self.app.authenticate(client_id, token):
            raise ServiceError(
                401,
                "unauthorized",
                "Valid client credentials are required.",
                {"WWW-Authenticate": "Bearer"},
            )

    def _read_json(self) -> Mapping[str, Any]:
        content_type = self.headers.get("Content-Type", "").split(";", 1)[0].strip().lower()
        if content_type != "application/json":
            raise ServiceError(415, "unsupported_media_type", "Content-Type must be application/json.")
        if self.headers.get("Transfer-Encoding") is not None:
            raise ServiceError(400, "unsupported_transfer_encoding", "Transfer-Encoding is not supported.")
        content_lengths = self.headers.get_all("Content-Length", [])
        if not content_lengths:
            raise ServiceError(411, "length_required", "Content-Length is required.")
        if len(content_lengths) != 1:
            raise ServiceError(400, "invalid_content_length", "Content-Length is invalid.")
        content_length = content_lengths[0]
        try:
            length = int(content_length)
        except ValueError as exc:
            raise ServiceError(400, "invalid_content_length", "Content-Length is invalid.") from exc
        if length <= 0:
            raise ServiceError(400, "empty_body", "A JSON request body is required.")
        if length > self.app.config.limits.max_body_bytes:
            raise ServiceError(413, "body_too_large", "The request body limit was exceeded.")
        try:
            body = self.rfile.read(length)
        except socket.timeout as exc:
            raise ServiceError(408, "request_timeout", "The request body timed out.") from exc
        if len(body) != length:
            raise ServiceError(400, "incomplete_body", "The request body was incomplete.")
        try:
            payload = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError) as exc:
            raise ServiceError(400, "invalid_json", "The request body must be valid UTF-8 JSON.") from exc
        if not isinstance(payload, Mapping):
            raise ServiceError(400, "invalid_json", "The JSON request body must be an object.")
        return payload

    def _validate_transcription(self, payload: Mapping[str, Any]) -> Tuple[bytes, str, str]:
        expected = {"audio_base64", "filename", "mime_type", "language"}
        if set(payload.keys()) != expected:
            raise ServiceError(
                400,
                "invalid_transcription_payload",
                "audio_base64, filename, mime_type and language are required.",
            )
        encoded = payload.get("audio_base64")
        filename = payload.get("filename")
        mime_type = payload.get("mime_type")
        language = payload.get("language")
        if not isinstance(encoded, str) or not encoded:
            raise ServiceError(400, "invalid_audio_base64", "audio_base64 must be a non-empty string.")
        if not isinstance(filename, str) or not FILENAME_PATTERN.fullmatch(filename):
            raise ServiceError(400, "invalid_filename", "filename is invalid.")
        if not isinstance(mime_type, str):
            raise ServiceError(400, "invalid_mime_type", "mime_type is invalid.")
        normalized_mime = mime_type.split(";", 1)[0].strip().lower()
        extension = SUPPORTED_MIME_TYPES.get(normalized_mime)
        if extension is None:
            raise ServiceError(415, "unsupported_audio_type", "The audio MIME type is not supported.")
        if not isinstance(language, str) or not LANGUAGE_PATTERN.fullmatch(language):
            raise ServiceError(400, "invalid_language", "language must be a valid language tag.")
        encoded_limit = ((self.app.config.limits.max_audio_bytes + 2) // 3) * 4
        if len(encoded) > encoded_limit:
            raise ServiceError(413, "audio_too_large", "The decoded audio limit was exceeded.")
        try:
            audio = base64.b64decode(encoded, validate=True)
        except (binascii.Error, ValueError) as exc:
            raise ServiceError(400, "invalid_audio_base64", "audio_base64 is not valid base64.") from exc
        if not audio:
            raise ServiceError(400, "empty_audio", "The decoded audio is empty.")
        if len(audio) > self.app.config.limits.max_audio_bytes:
            raise ServiceError(413, "audio_too_large", "The decoded audio limit was exceeded.")
        return audio, extension, language

    def _validate_speech(self, payload: Mapping[str, Any]) -> Tuple[str, float]:
        if set(payload.keys()) != {"text", "speed"}:
            raise ServiceError(400, "invalid_speech_payload", "text and speed are required.")
        text = payload.get("text")
        speed = payload.get("speed")
        if not isinstance(text, str):
            raise ServiceError(400, "invalid_text", "text must be a string.")
        normalized_text = re.sub(r"\s+", " ", text, flags=re.UNICODE).strip()
        if not normalized_text:
            raise ServiceError(400, "empty_text", "text must not be empty.")
        if len(normalized_text) > self.app.config.limits.max_text_chars:
            raise ServiceError(413, "text_too_large", "The text limit was exceeded.")
        if isinstance(speed, bool) or not isinstance(speed, (int, float)):
            raise ServiceError(400, "invalid_speed", "speed must be numeric.")
        numeric_speed = float(speed)
        if not math.isfinite(numeric_speed) or numeric_speed < 0.5 or numeric_speed > 2.0:
            raise ServiceError(400, "invalid_speed", "speed must be between 0.5 and 2.0.")
        return normalized_text, numeric_speed

    def _write_error(self, error: ServiceError, request_id: str) -> None:
        body = {
            "error": {"code": error.code, "message": error.message},
            "request_id": request_id,
        }
        self._write_json(error.status, body, request_id, error.headers)

    def _write_json(
        self,
        status: int,
        payload: Mapping[str, Any],
        request_id: str,
        extra_headers: Optional[Mapping[str, str]] = None,
    ) -> None:
        body = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self._common_headers(request_id, len(body), extra_headers)
        self.end_headers()
        self.wfile.write(body)

    def _write_audio(self, audio: bytes, request_id: str) -> None:
        self.send_response(200)
        self.send_header("Content-Type", "audio/wav")
        self._common_headers(request_id, len(audio))
        self.end_headers()
        self.wfile.write(audio)

    def _common_headers(
        self,
        request_id: str,
        content_length: int,
        extra_headers: Optional[Mapping[str, str]] = None,
    ) -> None:
        self.send_header("Content-Length", str(content_length))
        self.send_header("Cache-Control", "no-store")
        self.send_header("X-Content-Type-Options", "nosniff")
        self.send_header("X-Request-ID", request_id)
        self.send_header("Connection", "close")
        for key, value in (extra_headers or {}).items():
            self.send_header(key, value)
        self.close_connection = True

    def _method_not_allowed(self) -> None:
        self._dispatch(self.command)

    @staticmethod
    def _allowed_methods(route: str) -> str:
        if route in ("/healthz", "/v1/status"):
            return "GET"
        if route in ("/v1/transcriptions", "/v1/speech"):
            return "POST"
        return ""


def build_server(app: SpeechApplication, port: Optional[int] = None) -> SpeechHTTPServer:
    return SpeechHTTPServer((BIND_HOST, app.config.port if port is None else port), app)


def configure_logging() -> None:
    handler = logging.StreamHandler()
    handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    LOGGER.handlers[:] = [handler]
    LOGGER.setLevel(logging.INFO)
    LOGGER.propagate = False


def run_server(config: ServiceConfig) -> int:
    os.umask(0o077)
    configure_logging()
    engines = SpeechEngines(config)
    app = SpeechApplication(config, engines)
    server = build_server(app)

    def request_shutdown(signum: int, frame: Any) -> None:
        LOGGER.info("shutdown signal=%d", signum)
        def stop() -> None:
            app.shutdown()
            server.shutdown()

        threading.Thread(target=stop, daemon=True).start()

    signal.signal(signal.SIGTERM, request_shutdown)
    signal.signal(signal.SIGINT, request_shutdown)
    LOGGER.info("started bind=%s port=%d", BIND_HOST, server.server_port)
    try:
        server.serve_forever(poll_interval=0.5)
    finally:
        app.shutdown()
        server.server_close()
        LOGGER.info("stopped")
    return 0


def _hash_token_interactively() -> int:
    token = getpass.getpass("Client token: ")
    confirmation = getpass.getpass("Repeat client token: ")
    if not token or token != confirmation:
        raise ConfigurationError("the token is empty or the confirmation does not match")
    print(token_sha256(token))
    return 0


def main(argv: Optional[Sequence[str]] = None) -> int:
    parser = argparse.ArgumentParser(description="LMZ private loopback speech service")
    parser.add_argument(
        "--env-file",
        type=Path,
        help="absolute env file containing only LMZ_SPEECH_CONFIG=/absolute/config.json",
    )
    parser.add_argument("--check-config", action="store_true", help="validate config and engine readiness")
    parser.add_argument("--hash-token", action="store_true", help="prompt securely and print a SHA-256 token hash")
    args = parser.parse_args(argv)
    try:
        if args.hash_token:
            return _hash_token_interactively()
        if args.env_file:
            load_env_file(args.env_file)
        config = load_config_from_environment()
        if args.check_config:
            engines = SpeechEngines(config)
            readiness = engines.readiness()
            ready = all(value == "ready" for value in readiness.values())
            print(json.dumps({"status": "ready" if ready else "degraded", "engines": readiness}))
            return 0 if ready else 2
        return run_server(config)
    except ConfigurationError as exc:
        print("Configuration error: %s" % exc, file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
