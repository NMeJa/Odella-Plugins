# Odella-Plugins Directory Rules

1. **Always Edit Locally First**: Modify `.php`, `.js`, and `.css` files here in `c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins` rather than editing files directly on the remote server.
2. **Use `deploy.py` to Upload & Replace**:
   - Compare local vs. server: `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py" --check`
   - Build, upload, replace remote plugin, flush caches, and verify SHA-256 hashes:
     `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py"`
   - Deploy without bumping version:
     `python "c:\Users\Nick\Projects\Hostinger-Connect\Odella-Plugins\deploy.py" --no-bump`
