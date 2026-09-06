# RailTime project rules

## Mail signatures: binding rule from the user (2026-09-06)

- Embed the train animation only as a real HTML `<img>` element in signatures and email templates.
- Never substitute CSS `background-image`, a CSS `background` image shorthand, or an HTML `background` attribute for the train image. This also applies to import generation, runtime rendering, MIME delivery, and Outlook add-in transformations.
- Positioning the train visually behind content does not authorize changing its image source into a background. Keep its aspect ratio and test desktop/mobile behavior with actual received mail.
- Keep media embedded in the portable import and in actual mail delivery (CID attachments); do not silently replace them with remotely loaded images or claim a CID is an external media URL.
- This rule governs new work. Do not silently rewrite or publish existing saved signatures/templates, remove compatibility support, or loosen security validation. Preserve the user's release and test boundaries.
