# RailTime project rules

## Mail signatures: binding rule from the user (2026-09-06)

- Embed the train animation only as a real HTML `<img>` element in signatures and email templates.
- Never substitute CSS `background-image`, a CSS `background` image shorthand, or an HTML `background` attribute for the train image. This also applies to import generation, runtime rendering, MIME delivery, and Outlook add-in transformations.
- Positioning the train visually behind content does not authorize changing its image source into a background. Keep its aspect ratio and test desktop/mobile behavior with actual received mail.
- Keep media embedded in the portable import and in actual mail delivery (CID attachments); do not silently replace them with remotely loaded images or claim a CID is an external media URL.
- This rule governs new work. Do not silently rewrite or publish existing saved signatures/templates, remove compatibility support, or loosen security validation. Preserve the user's release and test boundaries.

<!-- project-design:begin -->
## Project design gallery

Before UI/UX work, consult `C:/Users/L. Zacharias/Documents/RailTime/project-design/index.html` and the original project components. Reuse and improve existing components before introducing duplicates. Keep the standalone gallery examples and source inventory in sync with UI changes.

The gallery is stored beside the canonical `.lmzdev` directory. Open `index.html` directly in a browser; viewing requires no XAMPP, PHP, web server or application login. Keep assets local and file-compatible. Optional native component exporters are development tools, not runtime dependencies. Do not expose the gallery through Laravel routes, bootstrap the app, read business records or call providers/SMTP. Show synthetic presentation examples and label backend-dependent actions honestly.
<!-- project-design:end -->
