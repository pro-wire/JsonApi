# JsonApi

Lean JSON REST API for ProcessWire. Exposes pages, templates, and fields as JSON at a configurable URL prefix. All access control is enforced through ProcessWire's native ACL — the API only returns or modifies what the current user is allowed to access.

## API variable

None. Routes are registered as URL hooks in `init()`.

## Default URL prefix

`/api/pw/`  (configurable in module settings as `apiPrefix`)

## Endpoints

### Pages

| Method | Path                     | Description                                        |
|--------|--------------------------|----------------------------------------------------|
| GET    | `/api/pw/pages/`         | List pages (use `selector` query param to filter)  |
| GET    | `/api/pw/pages/{id}/`    | Get single page by ID                              |
| POST   | `/api/pw/pages/`         | Create page (JSON body with `template`, `parent`, `title`, fields) |
| PUT    | `/api/pw/pages/{id}/`    | Update page fields                                 |
| DELETE | `/api/pw/pages/{id}/`    | Trash page                                         |

Query params for list endpoint:
- `selector` — any valid ProcessWire selector string
- `fields` — comma-separated field names to include in output
- `limit`, `start` — pagination

### Templates

| Method | Path                       | Description                                  |
|--------|----------------------------|----------------------------------------------|
| GET    | `/api/pw/templates/`       | List all templates                           |
| GET    | `/api/pw/templates/{name}/`| Template detail including field schema       |

The template schema response includes per-template field context: required, collapsed, `showIf`, `columnWidth`, label overrides — so frontends can render create/edit forms without hardcoding field layout.

### Fields

| Method | Path                    | Description           |
|--------|-------------------------|-----------------------|
| GET    | `/api/pw/fields/`       | List all fields       |
| GET    | `/api/pw/fields/{name}/`| Field detail          |

## Authentication

Authentication is handled by the **Auth** module. JsonApi only checks `$user->isLoggedin()` when the `requireLogin` setting is enabled.

| Setting        | Key             | Default | Description                                         |
|----------------|-----------------|---------|-----------------------------------------------------|
| API URL prefix | `apiPrefix`     | `/api/pw/` | URL prefix for all JsonApi routes                |
| Require Login  | `requireLogin`  | `1`     | Return 401 for unauthenticated requests             |

CORS and API key auth are configured in **Admin → Modules → Auth**.

## Request/response format

All responses are `application/json`. Request bodies must be `Content-Type: application/json`.

### Typical page response

```json
{
  "id": 1042,
  "name": "about",
  "title": "About Us",
  "template": "basic-page",
  "parent": "/",
  "url": "/about/",
  "fields": {
    "body": "<p>...</p>",
    "images": [...]
  }
}
```

### Error responses

```json
{ "error": true, "message": "Not found", "code": 404 }
```

## ACL enforcement

The API never bypasses ProcessWire's access control:
- `$pages->find()` and `$pages->get()` respect user roles and template access settings.
- To allow public read access, the relevant templates must have guest view permissions.
- Write endpoints check edit permissions before saving.

## Dependencies

Requires **Auth** module.

## Notes

- The API prefix must be unique and not conflict with other URL hooks (ApiRouter uses `/api/` but a different sub-path).
- Changing `apiPrefix` in module config takes effect immediately — no cache to clear.
- Field output follows ProcessWire's output formatting rules. Textformatter-processed values are returned for text fields.
