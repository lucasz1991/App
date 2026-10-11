"""Portable structural checks; deliberately not a substitute for Xcode compilation."""
import plistlib
import re
from pathlib import Path
import xml.etree.ElementTree as ET

base = Path(__file__).resolve().parents[1]
ios = base / "ios"
project = (ios / "RailTimeLocation.xcodeproj/project.pbxproj").read_text(encoding="utf-8")
objects = set(re.findall(r"^ ([A-F0-9]{24}) = \{ isa =", project, re.M))
assert len(objects) == 44, "Missing or duplicate Xcode objects"
references = set(re.findall(r"\b[A-F0-9]{24}\b", project))
assert references == objects, "Unresolved Xcode reference"
swift = sorted(path.name for path in (ios / "RailTimeLocation").glob("*.swift"))
for name in swift:
    assert f'path = "{name}"' in project, f"Source absent from Xcode project: {name}"
for name in ("Info.plist", "RailTimeLocation.entitlements", "PrivacyInfo.xcprivacy"):
    with (ios / "RailTimeLocation" / name).open("rb") as stream:
        data = plistlib.load(stream)
    assert data
info = plistlib.loads((ios / "RailTimeLocation/Info.plist").read_bytes())
assert info["UIBackgroundModes"] == ["location"]
assert info["NSAppTransportSecurity"]["NSAllowsArbitraryLoads"] is False
for path in (base / "android/app/src/main").rglob("*.xml"):
    ET.parse(path)
ET.parse(ios / "RailTimeLocation.xcodeproj/xcshareddata/xcschemes/RailTimeLocation.xcscheme")
manifest = (base / "android/app/src/main/AndroidManifest.xml").read_text(encoding="utf-8")
assert 'android:usesCleartextTraffic="false"' in manifest
assert 'android:foregroundServiceType="location"' in manifest
assert "BOOT_COMPLETED" not in manifest
assert "ACCESS_BACKGROUND_LOCATION" not in manifest
wrapper = (base / "android/gradle/wrapper/gradle-wrapper.properties").read_text(encoding="utf-8")
assert "distributionSha256Sum=d725d707bfabd4dfdc958c624003b3c80accc03f7037b5122c4b1d0ef15cecab" in wrapper
print(f"Native project structure valid: {len(objects)} Xcode objects, {len(swift)} app Swift sources, plists/XML and fail-closed manifests.")
