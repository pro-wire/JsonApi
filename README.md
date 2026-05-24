# JsonApi

Lean JSON REST API for ProcessWire that exposes core CMS data — pages, templates and fields — with full ACL enforcement via the existing PW session. Authentication is handled by whichever module you already use; this module only checks `$user->isLoggedin()`.

---

## What it does

- Exposes pages, templates and fields as JSON
- Respects PW's own ACL on every read, write and delete
- Returns full field schemas from templates so front-ends can render create/edit forms without hardcoding anything
- Per-template context (required, collapsed, showIf, columnWidth, label overrides) is included in schemas

---

## Installation

1. Copy the `JsonApi/` folder into `site/modules/`
2. Admin → Modules → Refresh → install **JsonApi**
3. Set your allowed CORS origins in the module config

---

## Configuration

| Setting | Description | Default |
|---|---|---|
| **API URL prefix** | URL segment for all routes | `pw-api` |
| **Allowed CORS origins** | One origin per line. `*` allows all | *(none)* |

---

## `.htaccess` — required

Inside your existing `RewriteEngine On` block, make sure the Authorization header passes through (needed if your auth module uses it):

```apache
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule .* - [e=HTTP_AUTHORIZATION:%1]

RewriteCond %{REQUEST_METHOD} OPTIONS
RewriteRule .* - [L]
```

---

## Endpoints

All endpoints return JSON. All require a valid PW session (HTTP 401 otherwise).

### Pages

```
GET  /pw-api/pages
GET  /pw-api/pages?template=blog-post&parent=/blog/&limit=25&start=0&sort=-modified
GET  /pw-api/pages?selector=template=blog-post, created>2024-01-01, sort=-created

GET  /pw-api/pages/{id}
GET  /pw-api/pages/{id}?schema=1    ← includes field schema for edit form rendering

POST /pw-api/pages/{id}             ← save fields (only fields the session user can edit)
Body: { "title": "New title", "body": "<p>…</p>", "tags": [1, 2] }

POST /pw-api/pages/new              ← create page
Body: { "template": "blog-post", "parent": "/blog/", "title": "My Post", "body": "…" }

DELETE /pw-api/pages/{id}           ← moves to trash (deleteable() check)
```

### Templates

```
GET /pw-api/templates               ← list all non-system templates
GET /pw-api/templates/{name}        ← full detail + field schema
```

### Fields

```
GET /pw-api/fields                  ← list all non-system fields
GET /pw-api/fields/{name}           ← full field detail
```

---

## Schema format

`GET /pw-api/templates/blog-post` returns:

```json
{
  "template": {
    "id": 5,
    "name": "blog-post",
    "label": "Blog Post",
    "fieldgroup": "blog-post",
    "schema": [
      {
        "id": 1,
        "name": "title",
        "label": "Title",
        "type": "FieldtypePageTitle",
        "inputfield": "InputfieldText",
        "required": true,
        "collapsed": 0,
        "columnWidth": 100,
        "maxlength": 255,
        "showIf": "",
        "requiredIf": ""
      },
      {
        "id": 8,
        "name": "body",
        "label": "Body",
        "type": "FieldtypeTextarea",
        "inputfield": "InputfieldTinyMCE",
        "required": false,
        "collapsed": 0,
        "columnWidth": 100
      },
      {
        "id": 12,
        "name": "category",
        "label": "Category",
        "type": "FieldtypePage",
        "inputfield": "InputfieldSelect",
        "required": true,
        "derefAsPage": 1,
        "parent_id": 1042,
        "template_id": 0,
        "findPagesSelector": ""
      },
      {
        "id": 15,
        "name": "tags",
        "label": "Tags",
        "type": "FieldtypeOptions",
        "inputfield": "InputfieldCheckboxes",
        "required": false,
        "options": [
          { "id": 1, "value": "tech",    "title": "Technology" },
          { "id": 2, "value": "design",  "title": "Design" }
        ]
      }
    ]
  }
}
```

You can use this schema to dynamically render a form — the `type` and `inputfield` tell you what input to render, `required`/`showIf`/`requiredIf` handle validation and conditional visibility, `columnWidth` handles layout.

---

## ACL — how it works

The module never bypasses PW's access control. All checks use native PW methods:

| Operation | PW check |
|---|---|
| Read page | `$page->viewable()` |
| Edit fields | `$page->editable()` + `$page->editable($field)` per field |
| Create page | `$parent->addable($template)` |
| Trash page | `$page->deleteable()` |

Fields the session user can't edit are silently skipped on POST and included in the `skipped` array in the response.

---

## Front-end usage (fetch)

```js
// Get a page with its field schema for an edit form
const res = await fetch('/pw-api/pages/1042?schema=1', {
  credentials: 'include', // sends the PW session cookie
});
const { page } = await res.json();

// Save changes
await fetch('/pw-api/pages/1042', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({ title: 'Updated title', body: '<p>New content</p>' }),
});

// Create a page
await fetch('/pw-api/pages/new', {
  method: 'POST',
  credentials: 'include',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    template: 'blog-post',
    parent:   '/blog/',
    title:    'My new post',
    body:     '<p>Hello world</p>',
  }),
});

// Full template schema (for a create form)
const { template } = await fetch('/pw-api/templates/blog-post', {
  credentials: 'include',
}).then(r => r.json());

// template.schema → array of field descriptors, ready to drive a form renderer
```

For cross-domain setups (Astro/React on a different server), your existing auth module handles login and sets the session cookie. Make sure it sets `SameSite=None; Secure` on the session cookie so cross-origin requests carry it, and add the front-end origin to the CORS allowed list in this module's config.
