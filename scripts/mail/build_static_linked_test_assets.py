"""Copy curated, immutable PNGs for the opt-in static linked mail test profile.

Usage: python scripts/mail/build_static_linked_test_assets.py [--check]

The 43 assets (42 retained plus one new caption) and 31 PNG selections are pinned by full SHA256,
byte length, MIME and dimensions. This build never renders or re-encodes an
image. It copies only the selected PNG bytes to public/m/<first10SHA256>.png.
No resources copy is needed: every original already has its canonical backup.
Existing outputs must match exactly and are never overwritten. --check writes
nothing, including when outputs are missing. No network or application bootstrap
is involved. Pillow validates the decoded file in addition to the PNG chunk
checks; no APNG chunks, symlink/junction paths or short-hash collisions are allowed.
"""

from __future__ import annotations

import argparse
import hashlib
import io
import json
import os
from pathlib import Path
import re
import stat
import struct
import sys
import zlib

from PIL import Image


APP = Path(__file__).absolute().parents[2]
PROFILE = "static-linked-test-v1"
MANIFEST_SOURCE_SHA256 = "79653592b00fb9dcf3547e7cbd130e7547d217275389d3c7568940612bd717d3"
MANIFEST = json.loads(r"""{
  "profile": "static-linked-test-v1",
  "source_marker": "data-rt-static=\"v1\"",
  "output_namespace": "/m/<first10SHA256>.png",
  "sources": [
    {
      "source": "icon-rt-v19-light.png",
      "sha256": "c91d7f93db31784cdce7eed0218904340c9b78e415ab4581d938cd1e01680fad",
      "bytes": 1531,
      "mime": "image/png",
      "dimensions": [
        132,
        132
      ],
      "png": "icon-rt-v19-light.png",
      "png_sha256": "c91d7f93db31784cdce7eed0218904340c9b78e415ab4581d938cd1e01680fad",
      "output": "/m/c91d7f93db.png"
    },
    {
      "source": "icon-rt-v19-dark.png",
      "sha256": "74ed4f5ff7bf5c00ba8d5b1513c8127336a2f65bf5ee2bb0938512eba580778c",
      "bytes": 1442,
      "mime": "image/png",
      "dimensions": [
        132,
        132
      ],
      "png": "icon-rt-v19-dark.png",
      "png_sha256": "74ed4f5ff7bf5c00ba8d5b1513c8127336a2f65bf5ee2bb0938512eba580778c",
      "output": "/m/74ed4f5ff7.png"
    },
    {
      "source": "wortmarke-signature-v19-light.png",
      "sha256": "b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a",
      "bytes": 6793,
      "mime": "image/png",
      "dimensions": [
        400,
        68
      ],
      "png": "wortmarke-signature-v19-light.png",
      "png_sha256": "b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a",
      "output": "/m/b983af0049.png"
    },
    {
      "source": "wortmarke-mail-v19-dark.png",
      "sha256": "24b9a9b1cdbd035f93e4d27307fde5efe2324ec9a5711c22a62f7ec6b0002b28",
      "bytes": 6383,
      "mime": "image/png",
      "dimensions": [
        400,
        68
      ],
      "png": "wortmarke-mail-v19-dark.png",
      "png_sha256": "24b9a9b1cdbd035f93e4d27307fde5efe2324ec9a5711c22a62f7ec6b0002b28",
      "output": "/m/24b9a9b1cd.png"
    },
    {
      "source": "contact-location.png",
      "sha256": "097e33e85eb0425fa5561c29f881f7f6fec5c9efc8f98704b30d8c9d2cc698a7",
      "bytes": 1915,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ],
      "png": "contact-location.png",
      "png_sha256": "097e33e85eb0425fa5561c29f881f7f6fec5c9efc8f98704b30d8c9d2cc698a7",
      "output": "/m/097e33e85e.png"
    },
    {
      "source": "contact-phone.png",
      "sha256": "741a9d43f5105e22e383167d582bc3a0dac1e25fde14b946e3b7c693e1d2fc3a",
      "bytes": 1851,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ],
      "png": "contact-phone.png",
      "png_sha256": "741a9d43f5105e22e383167d582bc3a0dac1e25fde14b946e3b7c693e1d2fc3a",
      "output": "/m/741a9d43f5.png"
    },
    {
      "source": "contact-mobile.png",
      "sha256": "8f6e4932f895cad94c310caa7b88d0ae95c8e71c556b1fe606d0251753cb487f",
      "bytes": 826,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ],
      "png": "contact-mobile.png",
      "png_sha256": "8f6e4932f895cad94c310caa7b88d0ae95c8e71c556b1fe606d0251753cb487f",
      "output": "/m/8f6e4932f8.png"
    },
    {
      "source": "contact-email.png",
      "sha256": "cfa8c6821180635369af1759ab5719f694aaa663e9fdefdc2dafcceac439f2dc",
      "bytes": 1556,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ],
      "png": "contact-email.png",
      "png_sha256": "cfa8c6821180635369af1759ab5719f694aaa663e9fdefdc2dafcceac439f2dc",
      "output": "/m/cfa8c68211.png"
    },
    {
      "source": "contact-web.png",
      "sha256": "09d2f890b7ad37987fc7798e4a4470d9a6dd919f5bf9e2bcded88ba1dedbe71b",
      "bytes": 2663,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ],
      "png": "contact-web.png",
      "png_sha256": "09d2f890b7ad37987fc7798e4a4470d9a6dd919f5bf9e2bcded88ba1dedbe71b",
      "output": "/m/09d2f890b7.png"
    },
    {
      "source": "outlook-hotline-fill-v1.png",
      "sha256": "3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583",
      "bytes": 75,
      "mime": "image/png",
      "dimensions": [
        1,
        34
      ],
      "png": "outlook-hotline-fill-v1.png",
      "png_sha256": "3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583",
      "output": "/m/3ee5033b5b.png"
    },
    {
      "source": "outlook-hotline-label-v1.png",
      "sha256": "c52d2cdb4861ec8714d6d33d45204a50dfd86de4b77c6d6c1f9f0d02185fd16d",
      "bytes": 6485,
      "mime": "image/png",
      "dimensions": [
        411,
        102
      ],
      "png": "outlook-hotline-label-v1.png",
      "png_sha256": "c52d2cdb4861ec8714d6d33d45204a50dfd86de4b77c6d6c1f9f0d02185fd16d",
      "output": "/m/c52d2cdb48.png"
    },
    {
      "source": "outlook-hotline-mail-v1.png",
      "sha256": "36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d",
      "bytes": 2415,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ],
      "png": "outlook-hotline-mail-v1.png",
      "png_sha256": "36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d",
      "output": "/m/36ecdeda31.png"
    },
    {
      "source": "outlook-hotline-phone-v1.png",
      "sha256": "a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5",
      "bytes": 4360,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ],
      "png": "outlook-hotline-phone-v1.png",
      "png_sha256": "a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5",
      "output": "/m/a3564d9bc8.png"
    },
    {
      "source": "zug-dampf-v27-delivery-light.png",
      "sha256": "e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e",
      "bytes": 8435,
      "mime": "image/png",
      "dimensions": [
        1205,
        151
      ],
      "png": "zug-dampf-v27-delivery-light.png",
      "png_sha256": "e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e",
      "output": "/m/e9f40cff8a.png"
    },
    {
      "source": "zug-dampf-v27-delivery-light.gif",
      "sha256": "e0f8327a1041e23bdbbb2a478978e81521efe4e021767e40ad30034ed61dbe53",
      "bytes": 225226,
      "mime": "image/gif",
      "dimensions": [
        1205,
        151
      ],
      "png": "zug-dampf-v27-delivery-light.png",
      "png_sha256": "e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e",
      "output": "/m/e9f40cff8a.png"
    },
    {
      "source": "zug-dampf-v27-delivery-dark.png",
      "sha256": "2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb",
      "bytes": 4317,
      "mime": "image/png",
      "dimensions": [
        1204,
        151
      ],
      "png": "zug-dampf-v27-delivery-dark.png",
      "png_sha256": "2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb",
      "output": "/m/2c170c402e.png"
    },
    {
      "source": "zug-dampf-v27-delivery-dark.gif",
      "sha256": "380b43ab43da0c724c492abf8f095917ad06408e7de07aaf33134bf82afb50e9",
      "bytes": 151255,
      "mime": "image/gif",
      "dimensions": [
        1204,
        151
      ],
      "png": "zug-dampf-v27-delivery-dark.png",
      "png_sha256": "2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb",
      "output": "/m/2c170c402e.png"
    },
    {
      "source": "zug-dampf-v27-delivery-light-mirrored.png",
      "sha256": "b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51",
      "bytes": 8506,
      "mime": "image/png",
      "dimensions": [
        1205,
        151
      ],
      "png": "zug-dampf-v27-delivery-light-mirrored.png",
      "png_sha256": "b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51",
      "output": "/m/b3f813d3f4.png"
    },
    {
      "source": "zug-dampf-v27-delivery-light-mirrored.gif",
      "sha256": "52c80c7a8a193472d50346a14a2876a857cce4f1dc98b944dfe98a7fbc94a840",
      "bytes": 225702,
      "mime": "image/gif",
      "dimensions": [
        1205,
        151
      ],
      "png": "zug-dampf-v27-delivery-light-mirrored.png",
      "png_sha256": "b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51",
      "output": "/m/b3f813d3f4.png"
    },
    {
      "source": "zug-dampf-v27-delivery-dark-mirrored.png",
      "sha256": "5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c",
      "bytes": 4295,
      "mime": "image/png",
      "dimensions": [
        1204,
        151
      ],
      "png": "zug-dampf-v27-delivery-dark-mirrored.png",
      "png_sha256": "5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c",
      "output": "/m/5784b3d25f.png"
    },
    {
      "source": "zug-dampf-v27-delivery-dark-mirrored.gif",
      "sha256": "adb9dbd54e0945c985a96f939867448b787a4990550d8cfefb16fa4c757a20e9",
      "bytes": 151273,
      "mime": "image/gif",
      "dimensions": [
        1204,
        151
      ],
      "png": "zug-dampf-v27-delivery-dark-mirrored.png",
      "png_sha256": "5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c",
      "output": "/m/5784b3d25f.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-light.png",
      "sha256": "78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a",
      "bytes": 5967,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-light.png",
      "png_sha256": "78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a",
      "output": "/m/78a1fd22c3.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-light.gif",
      "sha256": "45f4109cf7dbeed8c31c4c8a56bfdd9065b947b4b576596ba4bd6e5373b73fb8",
      "bytes": 142199,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-light.png",
      "png_sha256": "78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a",
      "output": "/m/78a1fd22c3.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-dark.png",
      "sha256": "b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd",
      "bytes": 2918,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-dark.png",
      "png_sha256": "b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd",
      "output": "/m/b1bc9d62a6.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-dark.gif",
      "sha256": "822dd45cf0529e41d8e1ed9a082904581d76be4a9f25c39240111e11fd3d3554",
      "bytes": 98641,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-dark.png",
      "png_sha256": "b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd",
      "output": "/m/b1bc9d62a6.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-light-mirrored.png",
      "sha256": "dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6",
      "bytes": 5983,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-light-mirrored.png",
      "png_sha256": "dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6",
      "output": "/m/dc6eb47779.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-light-mirrored.gif",
      "sha256": "9db04d54004742501156413430e77ccf1b1ed8515018caa9c7d00caedaf63f17",
      "bytes": 142477,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-light-mirrored.png",
      "png_sha256": "dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6",
      "output": "/m/dc6eb47779.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-dark-mirrored.png",
      "sha256": "4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78",
      "bytes": 2884,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-dark-mirrored.png",
      "png_sha256": "4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78",
      "output": "/m/4b6995aa47.png"
    },
    {
      "source": "zug-dampf-outlook-intrinsic-v1-dark-mirrored.gif",
      "sha256": "18567efab2a728b8058195b8fef1651e97b2aff8c116261af9698afabdaad4d9",
      "bytes": 98776,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-intrinsic-v1-dark-mirrored.png",
      "png_sha256": "4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78",
      "output": "/m/4b6995aa47.png"
    },
    {
      "source": "outlook-hotline-fill-outline-v1.png",
      "sha256": "a27ccfb5bfa433fb8d4a13401a3ea1ffbf1d77482e0ba1162a041f218548abe3",
      "bytes": 76,
      "mime": "image/png",
      "dimensions": [
        1,
        34
      ],
      "png": "outlook-hotline-fill-outline-v1.png",
      "png_sha256": "a27ccfb5bfa433fb8d4a13401a3ea1ffbf1d77482e0ba1162a041f218548abe3",
      "output": "/m/a27ccfb5bf.png"
    },
    {
      "source": "outlook-hotline-label-outline-v1.png",
      "sha256": "003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec",
      "bytes": 6474,
      "mime": "image/png",
      "dimensions": [
        552,
        102
      ],
      "png": "outlook-hotline-label-outline-v1.png",
      "png_sha256": "003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec",
      "output": "/m/003919f6fe.png"
    },
    {
      "source": "outlook-hotline-mail-outline-v1.png",
      "sha256": "dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d",
      "bytes": 1684,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ],
      "png": "outlook-hotline-mail-outline-v1.png",
      "png_sha256": "dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d",
      "output": "/m/dcf209bd17.png"
    },
    {
      "source": "outlook-hotline-phone-outline-v1.png",
      "sha256": "e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1",
      "bytes": 2924,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ],
      "png": "outlook-hotline-phone-outline-v1.png",
      "png_sha256": "e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1",
      "output": "/m/e24cb8b0ac.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-light.png",
      "sha256": "0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258",
      "bytes": 7371,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-light.png",
      "png_sha256": "0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258",
      "output": "/m/0b9b8d55f6.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-light.gif",
      "sha256": "47007453902679fbc96eda87bb8701db7f701b2c340d83e9561fa68c4dbd503d",
      "bytes": 179636,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-light.png",
      "png_sha256": "0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258",
      "output": "/m/0b9b8d55f6.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-dark.png",
      "sha256": "d8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3",
      "bytes": 4411,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-dark.png",
      "png_sha256": "d8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3",
      "output": "/m/d8370fc0cc.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-dark.gif",
      "sha256": "243786c7e426e2f06165858c8a1883cfd5598b72df1247c7cc2b3ff6e41220a5",
      "bytes": 130062,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-dark.png",
      "png_sha256": "d8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3",
      "output": "/m/d8370fc0cc.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-light-mirrored.png",
      "sha256": "b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792",
      "bytes": 7572,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-light-mirrored.png",
      "png_sha256": "b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792",
      "output": "/m/b6d051cacd.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-light-mirrored.gif",
      "sha256": "67d1600699cad2f1f2af69ea59a9d88a7085f26556966f083ab615401e430d39",
      "bytes": 178583,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-light-mirrored.png",
      "png_sha256": "b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792",
      "output": "/m/b6d051cacd.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-dark-mirrored.png",
      "sha256": "917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b",
      "bytes": 4566,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-dark-mirrored.png",
      "png_sha256": "917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b",
      "output": "/m/917e8f055d.png"
    },
    {
      "source": "zug-dampf-outlook-brand-v1-dark-mirrored.gif",
      "sha256": "ed21ba08d9d32ede363a30d13d182aa802b426330fd0110c4b0f50b11c960768",
      "bytes": 129369,
      "mime": "image/gif",
      "dimensions": [
        695,
        151
      ],
      "png": "zug-dampf-outlook-brand-v1-dark-mirrored.png",
      "png_sha256": "917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b",
      "output": "/m/917e8f055d.png"
    },
    {
      "source": "outlook-hotline-label-inktop-v1.png",
      "sha256": "37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a",
      "bytes": 6293,
      "mime": "image/png",
      "dimensions": [
        552,
        77
      ],
      "png": "outlook-hotline-label-inktop-v1.png",
      "png_sha256": "37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a",
      "output": "/m/37683e55f5.png"
    },
    {
      "source": "outlook-dispo-card-caption-v1.png",
      "sha256": "464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7",
      "bytes": 6597,
      "mime": "image/png",
      "dimensions": [
        398,
        76
      ],
      "png": "outlook-dispo-card-caption-v1.png",
      "png_sha256": "464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7",
      "output": "/m/464054a84f.png"
    }
  ],
  "targets": {
    "/m/097e33e85e.png": {
      "source": "contact-location.png",
      "sha256": "097e33e85eb0425fa5561c29f881f7f6fec5c9efc8f98704b30d8c9d2cc698a7",
      "bytes": 1915,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ]
    },
    "/m/09d2f890b7.png": {
      "source": "contact-web.png",
      "sha256": "09d2f890b7ad37987fc7798e4a4470d9a6dd919f5bf9e2bcded88ba1dedbe71b",
      "bytes": 2663,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ]
    },
    "/m/24b9a9b1cd.png": {
      "source": "wortmarke-mail-v19-dark.png",
      "sha256": "24b9a9b1cdbd035f93e4d27307fde5efe2324ec9a5711c22a62f7ec6b0002b28",
      "bytes": 6383,
      "mime": "image/png",
      "dimensions": [
        400,
        68
      ]
    },
    "/m/2c170c402e.png": {
      "source": "zug-dampf-v27-delivery-dark.png",
      "sha256": "2c170c402e17b74e45dc450494fa8f796f172b1fcb8cdfd424b361ceb9bd78bb",
      "bytes": 4317,
      "mime": "image/png",
      "dimensions": [
        1204,
        151
      ]
    },
    "/m/36ecdeda31.png": {
      "source": "outlook-hotline-mail-v1.png",
      "sha256": "36ecdeda3135132910ead299dec2fdef8786ed05a39d7fadb3ee4156e5d1696d",
      "bytes": 2415,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ]
    },
    "/m/3ee5033b5b.png": {
      "source": "outlook-hotline-fill-v1.png",
      "sha256": "3ee5033b5b112852a0255cf62d99542391117ba462408510c460c799a5ad6583",
      "bytes": 75,
      "mime": "image/png",
      "dimensions": [
        1,
        34
      ]
    },
    "/m/4b6995aa47.png": {
      "source": "zug-dampf-outlook-intrinsic-v1-dark-mirrored.png",
      "sha256": "4b6995aa470fb7730c0efa88d95528eb053bf95e3314a9567f2d36084370bd78",
      "bytes": 2884,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/5784b3d25f.png": {
      "source": "zug-dampf-v27-delivery-dark-mirrored.png",
      "sha256": "5784b3d25f89394f4196edfaf032f6db33bbf1b85880de8eff8a50573e17281c",
      "bytes": 4295,
      "mime": "image/png",
      "dimensions": [
        1204,
        151
      ]
    },
    "/m/741a9d43f5.png": {
      "source": "contact-phone.png",
      "sha256": "741a9d43f5105e22e383167d582bc3a0dac1e25fde14b946e3b7c693e1d2fc3a",
      "bytes": 1851,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ]
    },
    "/m/74ed4f5ff7.png": {
      "source": "icon-rt-v19-dark.png",
      "sha256": "74ed4f5ff7bf5c00ba8d5b1513c8127336a2f65bf5ee2bb0938512eba580778c",
      "bytes": 1442,
      "mime": "image/png",
      "dimensions": [
        132,
        132
      ]
    },
    "/m/78a1fd22c3.png": {
      "source": "zug-dampf-outlook-intrinsic-v1-light.png",
      "sha256": "78a1fd22c31977b21286a40b5aafe51b957b63b49f40948b6ed2b21ba0651d4a",
      "bytes": 5967,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/8f6e4932f8.png": {
      "source": "contact-mobile.png",
      "sha256": "8f6e4932f895cad94c310caa7b88d0ae95c8e71c556b1fe606d0251753cb487f",
      "bytes": 826,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ]
    },
    "/m/a3564d9bc8.png": {
      "source": "outlook-hotline-phone-v1.png",
      "sha256": "a3564d9bc8fafc2fc9dcd577361b882dda579f539049876e589fd6d12b090dc5",
      "bytes": 4360,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ]
    },
    "/m/b1bc9d62a6.png": {
      "source": "zug-dampf-outlook-intrinsic-v1-dark.png",
      "sha256": "b1bc9d62a6dd026ffc9e6f0c181d1586084104223afa272766ee38371e4072dd",
      "bytes": 2918,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/b3f813d3f4.png": {
      "source": "zug-dampf-v27-delivery-light-mirrored.png",
      "sha256": "b3f813d3f405153f0dfc75b23118d98057170d38b4d35fba0797b5649f474b51",
      "bytes": 8506,
      "mime": "image/png",
      "dimensions": [
        1205,
        151
      ]
    },
    "/m/b983af0049.png": {
      "source": "wortmarke-signature-v19-light.png",
      "sha256": "b983af0049926c128807d605a51a68ce90fea06c5c340f7728b105a96d7f021a",
      "bytes": 6793,
      "mime": "image/png",
      "dimensions": [
        400,
        68
      ]
    },
    "/m/c52d2cdb48.png": {
      "source": "outlook-hotline-label-v1.png",
      "sha256": "c52d2cdb4861ec8714d6d33d45204a50dfd86de4b77c6d6c1f9f0d02185fd16d",
      "bytes": 6485,
      "mime": "image/png",
      "dimensions": [
        411,
        102
      ]
    },
    "/m/c91d7f93db.png": {
      "source": "icon-rt-v19-light.png",
      "sha256": "c91d7f93db31784cdce7eed0218904340c9b78e415ab4581d938cd1e01680fad",
      "bytes": 1531,
      "mime": "image/png",
      "dimensions": [
        132,
        132
      ]
    },
    "/m/cfa8c68211.png": {
      "source": "contact-email.png",
      "sha256": "cfa8c6821180635369af1759ab5719f694aaa663e9fdefdc2dafcceac439f2dc",
      "bytes": 1556,
      "mime": "image/png",
      "dimensions": [
        44,
        44
      ]
    },
    "/m/dc6eb47779.png": {
      "source": "zug-dampf-outlook-intrinsic-v1-light-mirrored.png",
      "sha256": "dc6eb47779cca236195052802aac7c1e22dba1f8198fbfbadb5729b504df99d6",
      "bytes": 5983,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/e9f40cff8a.png": {
      "source": "zug-dampf-v27-delivery-light.png",
      "sha256": "e9f40cff8a9a047bba5842cf0695e346ac229c7d1dfa4b78393c60e02b814e8e",
      "bytes": 8435,
      "mime": "image/png",
      "dimensions": [
        1205,
        151
      ]
    },
    "/m/a27ccfb5bf.png": {
      "source": "outlook-hotline-fill-outline-v1.png",
      "sha256": "a27ccfb5bfa433fb8d4a13401a3ea1ffbf1d77482e0ba1162a041f218548abe3",
      "bytes": 76,
      "mime": "image/png",
      "dimensions": [
        1,
        34
      ]
    },
    "/m/003919f6fe.png": {
      "source": "outlook-hotline-label-outline-v1.png",
      "sha256": "003919f6fe18e62a958217d165f9c3a93602f513af78bbd1470aaa6dc95a18ec",
      "bytes": 6474,
      "mime": "image/png",
      "dimensions": [
        552,
        102
      ]
    },
    "/m/dcf209bd17.png": {
      "source": "outlook-hotline-mail-outline-v1.png",
      "sha256": "dcf209bd170e4581760ec5c8183830d445cd4fc2e46277bc5ff265bacbc85e9d",
      "bytes": 1684,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ]
    },
    "/m/e24cb8b0ac.png": {
      "source": "outlook-hotline-phone-outline-v1.png",
      "sha256": "e24cb8b0ac6770bd9b2a453c50b7c7157531f5929e96713f9aa03d42647f3ea1",
      "bytes": 2924,
      "mime": "image/png",
      "dimensions": [
        126,
        102
      ]
    },
    "/m/0b9b8d55f6.png": {
      "source": "zug-dampf-outlook-brand-v1-light.png",
      "sha256": "0b9b8d55f6a2343198a721915fe2fa2e61df6eb1e52f72bb91f32a4c4a2a5258",
      "bytes": 7371,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/d8370fc0cc.png": {
      "source": "zug-dampf-outlook-brand-v1-dark.png",
      "sha256": "d8370fc0cc4d87ddad0a076cb5c2468e36ad1f5667966418cbfc2c519bd878b3",
      "bytes": 4411,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/b6d051cacd.png": {
      "source": "zug-dampf-outlook-brand-v1-light-mirrored.png",
      "sha256": "b6d051cacd77535d71366db071b9c077fdbd7d4d487eb369fed2a77fbe142792",
      "bytes": 7572,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/917e8f055d.png": {
      "source": "zug-dampf-outlook-brand-v1-dark-mirrored.png",
      "sha256": "917e8f055d74b6e4b2294df05fb74079cfb5e8526902cc87fda2e260b658861b",
      "bytes": 4566,
      "mime": "image/png",
      "dimensions": [
        695,
        151
      ]
    },
    "/m/37683e55f5.png": {
      "source": "outlook-hotline-label-inktop-v1.png",
      "sha256": "37683e55f5469c8593450bfeaeea13ed0b1849976dce15b7479d7095d998540a",
      "bytes": 6293,
      "mime": "image/png",
      "dimensions": [
        552,
        77
      ]
    },
    "/m/464054a84f.png": {
      "source": "outlook-dispo-card-caption-v1.png",
      "sha256": "464054a84f32ad05d4c6e65a67105c6500ed070a818a40935d3b9f390bec7ab7",
      "bytes": 6597,
      "mime": "image/png",
      "dimensions": [
        398,
        76
      ]
    }
  }
}""")
PNG_SIGNATURE = b"\x89PNG\r\n\x1a\n"
ANIMATION_CHUNKS = {b"acTL", b"fcTL", b"fdAT"}


def digest(content: bytes) -> str:
    return hashlib.sha256(content).hexdigest()


def filename(value: object, extension: str | None = None) -> str:
    if not isinstance(value, str) or not re.fullmatch(r"[a-z0-9][a-z0-9-]*\.(?:png|gif)", value):
        raise ValueError("Asset filename is outside the curated flat namespace")
    if extension is not None and not value.endswith(extension):
        raise ValueError("Curated target must be a PNG")
    return value


def metadata(value: dict) -> None:
    if not re.fullmatch(r"[0-9a-f]{64}", str(value.get("sha256", ""))):
        raise ValueError("Asset requires a full lowercase SHA256 pin")
    size = value.get("bytes")
    dimensions = value.get("dimensions")
    if type(size) is not int or size <= 0:
        raise ValueError("Asset requires a positive pinned byte length")
    if not isinstance(dimensions, list) or len(dimensions) != 2 or any(type(side) is not int or not 0 < side <= 16384 for side in dimensions):
        raise ValueError("Asset requires pinned dimensions")
    if value.get("mime") not in {"image/png", "image/gif"}:
        raise ValueError("Unsupported curated asset MIME")


def validate_manifest(manifest: dict) -> None:
    if manifest.get("profile") != PROFILE or manifest.get("output_namespace") != "/m/<first10SHA256>.png":
        raise ValueError("Unexpected static linked profile or output namespace")
    sources = manifest.get("sources")
    targets = manifest.get("targets")
    if not isinstance(sources, list) or not sources or not isinstance(targets, dict) or not targets:
        raise ValueError("Pinned source and target inventories are required")
    prefixes: dict[str, str] = {}
    for target in targets.values():
        metadata(target)
        prefix = target["sha256"][:10]
        if prefix in prefixes and prefixes[prefix] != target["sha256"]:
            raise ValueError("Short SHA256 prefix collision")
        prefixes[prefix] = target["sha256"]
    source_map = {}
    for source in sources:
        metadata(source)
        name = filename(source.get("source"))
        filename(source.get("png"), ".png")
        if name in source_map:
            raise ValueError("Duplicate original source pin")
        if source["mime"] != ("image/png" if name.endswith(".png") else "image/gif"):
            raise ValueError("Pinned source extension and MIME differ")
        source_map[name] = source
    referenced = set()
    for output, target in targets.items():
        name = filename(target.get("source"), ".png")
        if output != "/m/" + target["sha256"][:10] + ".png" or target["mime"] != "image/png":
            raise ValueError("Target does not match its pinned PNG SHA256 URL")
        original = source_map.get(name)
        if original is None or any(original[key] != target[key] for key in ("sha256", "bytes", "mime", "dimensions")):
            raise ValueError("Target PNG must be an exact pinned original")
    for source in sources:
        target = targets.get(source.get("output"))
        if target is None or source["png"] != target["source"] or source.get("png_sha256") != target["sha256"]:
            raise ValueError("Original-to-PNG projection is outside the pinned manifest")
        if source["mime"] == "image/png" and (source["source"] != source["png"] or source["sha256"] != source["png_sha256"]):
            raise ValueError("PNG originals must remain byte-exact")
        referenced.add(source["output"])
    if referenced != set(targets):
        raise ValueError("An unreferenced PNG target is not allowed")


def secure_path(app: Path, relative: str) -> Path:
    """Check lexical ancestors before resolving, so links are never hidden."""
    app = app.absolute()
    relative_path = Path(relative)
    if relative_path.is_absolute() or ".." in relative_path.parts:
        raise ValueError("Asset path escapes the application root")
    path = app / relative_path
    for component in (*reversed(path.parents), path):
        if component.is_symlink() or (hasattr(component, "is_junction") and component.is_junction()):
            raise ValueError("Symlink or junction asset paths are refused")
    if not path.resolve().is_relative_to(app.resolve()):
        raise ValueError("Resolved asset path escapes the application root")
    return path


def png_chunks(content: bytes) -> tuple[int, int]:
    if not content.startswith(PNG_SIGNATURE):
        raise ValueError("PNG signature mismatch")
    offset = len(PNG_SIGNATURE)
    dimensions = None
    saw_data = False
    while offset < len(content):
        if len(content) - offset < 12:
            raise ValueError("Truncated PNG chunk")
        size = struct.unpack_from(">I", content, offset)[0]
        kind = content[offset + 4:offset + 8]
        end = offset + 12 + size
        if end > len(content):
            raise ValueError("PNG chunk exceeds the pinned file")
        payload = content[offset + 8:offset + 8 + size]
        crc = struct.unpack_from(">I", content, offset + 8 + size)[0]
        if zlib.crc32(kind + payload) & 0xFFFFFFFF != crc:
            raise ValueError("PNG chunk CRC mismatch")
        if kind in ANIMATION_CHUNKS:
            raise ValueError("APNG animation chunks are refused")
        if dimensions is None:
            if kind != b"IHDR" or size != 13:
                raise ValueError("PNG must begin with a single IHDR")
            dimensions = struct.unpack_from(">II", payload)
        elif kind == b"IHDR":
            raise ValueError("Duplicate PNG IHDR")
        if kind == b"IDAT":
            saw_data = True
        if kind == b"IEND":
            if size != 0 or not saw_data or end != len(content):
                raise ValueError("PNG IEND, image data or trailing bytes are invalid")
            return dimensions
        offset = end
    raise ValueError("PNG has no terminal IEND")


def validate_image(content: bytes, pin: dict) -> None:
    if len(content) != pin["bytes"] or digest(content) != pin["sha256"]:
        raise ValueError("Curated asset bytes or full SHA256 changed")
    if pin["mime"] == "image/png" and png_chunks(content) != tuple(pin["dimensions"]):
        raise ValueError("PNG IHDR dimensions differ from the pinned dimensions")
    with Image.open(io.BytesIO(content)) as image:
        if Image.MIME.get(image.format) != pin["mime"] or image.size != tuple(pin["dimensions"]):
            raise ValueError("Decoded asset MIME or dimensions differ from the pins")
        if pin["mime"] == "image/png" and (getattr(image, "n_frames", 1) != 1 or getattr(image, "is_animated", False)):
            raise ValueError("Only a single static PNG frame is allowed")
        image.verify()
    with Image.open(io.BytesIO(content)) as image:
        image.load()


def read_asset(app: Path, relative: str, pin: dict) -> bytes:
    path = secure_path(app, relative)
    if not stat.S_ISREG(path.stat().st_mode):
        raise ValueError("Curated asset must be a regular file")
    content = path.read_bytes()
    validate_image(content, pin)
    return content


def build_assets(app: Path = APP, manifest: dict = MANIFEST, *, check: bool = False) -> dict:
    validate_manifest(manifest)
    sources = {}
    for pin in manifest["sources"]:
        sources[pin["source"]] = read_asset(app, "public/mail-assets/" + pin["source"], pin)
    # All originals and existing outputs pass before creating any new path.
    missing = []
    for output, pin in sorted(manifest["targets"].items()):
        path = secure_path(app, "public" + output)
        if path.exists():
            if read_asset(app, "public" + output, pin) != sources[pin["source"]]:
                raise ValueError("Existing static linked output differs from its original PNG")
        else:
            missing.append((output, pin))
    if check and missing:
        raise ValueError("Missing static linked outputs: " + ", ".join(output for output, _ in missing))
    if not check and missing:
        secure_path(app, "public/m").mkdir(exist_ok=True)
        for output, pin in missing:
            path = secure_path(app, "public" + output)
            flags = os.O_WRONLY | os.O_CREAT | os.O_EXCL | getattr(os, "O_BINARY", 0) | getattr(os, "O_NOFOLLOW", 0)
            with os.fdopen(os.open(path, flags, 0o644), "wb") as stream:
                stream.write(sources[pin["source"]])
            if read_asset(app, "public" + output, pin) != sources[pin["source"]]:
                raise ValueError("New PNG copy is not byte-exact")
    for pin in manifest["sources"]:
        if read_asset(app, "public/mail-assets/" + pin["source"], pin) != sources[pin["source"]]:
            raise ValueError("Original asset changed during the build")
    return {
        "profile": manifest["profile"], "mode": "check" if check else "build",
        "source_assets_verified": len(sources), "png_targets_verified": len(manifest["targets"]),
        "png_bytes": sum(pin["bytes"] for pin in manifest["targets"].values()),
        "created": 0 if check else len(missing), "originals_unchanged": True,
        "targets": {output: pin["sha256"] for output, pin in sorted(manifest["targets"].items())},
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true", help="Verify exact existing outputs without writing files or directories")
    arguments = parser.parse_args()
    try:
        if len(MANIFEST["sources"]) != 43 or len(MANIFEST["targets"]) != 31:
            raise ValueError("The approved 43-source / 31-target inventory changed")
        report = build_assets(check=arguments.check)
    except (OSError, ValueError, SyntaxError) as error:
        print("Static linked asset verification failed: " + str(error), file=sys.stderr)
        return 1
    print(json.dumps(report, indent=2, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
