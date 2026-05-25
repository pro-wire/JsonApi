<?php

namespace ProcessWire;

/**
 * JsonApi
 *
 * Exposes core ProcessWire data — pages, templates, fields —
 * as a JSON REST API. Authentication is handled entirely by your existing
 * auth module; this module only checks that a PW session exists and then
 * enforces normal PW ACL (page access, editability, field access).
 *
 * Endpoints
 * ─────────────────────────────────────────────────────────────────────
 *   GET  /api/pw/pages                     list pages (filterable)
 *   GET  /api/pw/pages/{id|name|path}      single page + field values
 *   POST /api/pw/pages/{id}                save field values (ACL enforced)
 *   POST /api/pw/pages/new                 create a page
 *   DELETE /api/pw/pages/{id}              trash a page
 *
 *   GET  /api/pw/templates                 list all templates
 *   GET  /api/pw/templates/{name}          template detail + full field schema
 *
 *   GET  /api/pw/fields                    list all fields
 *   GET  /api/pw/fields/{name}             single field detail
 *
 *
 * 
 * @author Ivan Milincic <ivan@milincic.com>
 * @license MIT
 */
class JsonApi extends WireData implements Module, ConfigurableModule {

	public static function getModuleInfo(): array {
		return [
			'title'    => 'JSON API',
			'version'  => 200,
			'summary'  => 'ProcessWire JSON REST API exposing pages, templates and fields. Auth delegated to session.',
			'author'   => 'Ivan Milincic',
			'singular' => true,
			'autoload' => true,
			'icon'     => 'code-fork',
			'requires' => ['ProcessWire>=3.0.164', 'Auth'],
		];
	}

	public static function getDefaultData(): array {
		return [
			'apiPrefix'    => '/api/pw/',
			'requireLogin' => 1,
		];
	}

	// ─────────────────────────────────────────────────────────────────
	// Bootstrap
	// ─────────────────────────────────────────────────────────────────

	public function init(): void {
		$this->addHookBefore('ProcessPageView::execute', $this, 'intercept');
	}

	public function intercept(HookEvent $event): void {
		$prefix = '/' . trim($this->get('apiPrefix') ?: '/api/pw/', '/') . '/';
		$prefix = str_replace('//', '/', $prefix); // just in case
		$url    = wire('input')->url();

		if (!str_starts_with($url, $prefix)) return;

		// Take over rendering
		$event->replace = true;
		$event->return  = '';

		/** @var Auth $auth */
		$auth = wire('modules')->get('Auth');
		$auth->sendCorsHeaders();

		// OPTIONS preflight must be handled before any auth check —
		// browsers send preflights without credentials.
		if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
			http_response_code(204);
			exit;
		}

		header('Content-Type: application/json; charset=utf-8');

		// API key check — controlled by Auth module config.
		if ($auth->requiresApiKey() && !$auth->validateApiKey()) {
			$this->respond(401, ['error' => 'Invalid or missing API key']);
			exit;
		}

		// Session check — skipped when "requireLogin" is disabled in module config
		if ($this->get('requireLogin') && !wire('user')->isLoggedin()) {
			$this->respond(401, ['error' => 'Not authenticated']);
			exit;
		}

		$path   = trim(substr($url, strlen($prefix)), '/');
		$method = strtoupper($_SERVER['REQUEST_METHOD']);

		try {
			$this->dispatch($path, $method);
		} catch (ProcessWireJsonApiException $e) {
			$this->respond($e->getCode(), ['error' => $e->getMessage()]);
		} catch (\Exception $e) {
			wire('log')->error('ProcessWireJsonApi: ' . $e->getMessage());
			$this->respond(500, ['error' => 'Server error']);
		}

		exit;
	}

	// ─────────────────────────────────────────────────────────────────
	// Router
	// ─────────────────────────────────────────────────────────────────

	private function dispatch(string $path, string $method): void {
		$parts    = $path === '' ? [] : explode('/', $path, 2);
		$resource = $parts[0] ?? '';
		$id       = isset($parts[1]) ? trim($parts[1], '/') : '';

		match ($resource) {
			'pages'     => $this->routePages($id, $method),
			'templates' => $this->routeTemplates($id, $method),
			'fields'    => $this->routeFields($id, $method),
			default     => throw new ProcessWireJsonApiException('Unknown resource', 404),
		};
	}

	// ─────────────────────────────────────────────────────────────────
	// Pages
	// ─────────────────────────────────────────────────────────────────

	private function routePages(string $id, string $method): void {
		if ($id === 'new' && $method === 'POST') {
			$this->pageCreate();
			return;
		}

		match ($method) {
			'GET'    => $id ? $this->pageGet($id)    : $this->pageList(),
			'POST'   => $id ? $this->pageSave($id)   : throw new ProcessWireJsonApiException('Provide a page id to save', 400),
			'DELETE' => $id ? $this->pageDelete($id) : throw new ProcessWireJsonApiException('Provide a page id to delete', 400),
			default  => throw new ProcessWireJsonApiException('Method not allowed', 405),
		};
	}

	/** GET /api/pw/pages[?template=&parent=&limit=&start=&sort=&selector=] */
	private function pageList(): void {
		$get = wire('input')->get;
		$san = wire('sanitizer');

		$excludedPageNames = "trash|http404";

		// Exclude the admin root page and all its descendants
		$adminRoot    = wire('pages')->get('parent.id=1, template=admin');
		$adminExclude = $adminRoot->id ? ", id!={$adminRoot->id}, has_parent!={$adminRoot->id}" : '';

		// Power-user escape hatch: raw PW selector string
		$raw = $san->text($get->text('selector'));
		if ($raw) {
			// Still prevent querying trashed pages unless explicitly requested
			if (strpos($raw, 'status') === false) $raw .= ', status<' . Page::statusTrash;
			$raw .= $adminExclude;
			$pages = wire('pages')->find($raw);
		} else {
			$limit    = min((int)($get->int('limit') ?: 25), 200);
			$start    = (int)$get->int('start');
			$sort     = $san->pageName($get->text('sort') ?: 'sort');
			$template = $san->pageName($get->text('template'));
			$parent   = $san->text($get->text('parent'));

			$sel = "limit=$limit, start=$start, sort=$sort, status<" . Page::statusTrash . $adminExclude . ", name!={$excludedPageNames}";
			if ($template) $sel .= ", template=$template";
			if ($parent)   $sel .= ', has_parent=' . $san->selectorValue($parent);

			$pages = wire('pages')->find($sel);
		}

		$this->respond(200, [
			'total' => $pages->getTotal(),
			'count' => $pages->count(),
			'start' => (int)($get->int('start') ?? 0),
			'items' => array_map([$this, 'formatPageBrief'], $pages->getArray()),
		]);
	}

	/** GET /api/pw/pages/{id|name|path}[?schema=1] */
	private function pageGet(string $id): void {
		$page = $this->resolvePage($id);

		// Pass false to skip the template-file-existence check; all ACL checks still apply
		if (!$page->viewable(false)) throw new ProcessWireJsonApiException('Access denied', 403);

		$data = $this->formatPageFull($page);

		// Optionally attach the template field schema so the front-end
		// can render an edit form without a separate /templates request
		if (wire('input')->get->bool('schema')) {
			$data['schema'] = $this->schemaForTemplate($page->template);
		}

		$this->respond(200, ['page' => $data]);
	}

	/** POST /api/pw/pages/{id}  body: { field: value, ... } */
	private function pageSave(string $id): void {
		$page = $this->resolvePage($id);

		if (!$page->editable()) throw new ProcessWireJsonApiException('Access denied', 403);

		$body = $this->jsonBody();
		if (!$body) throw new ProcessWireJsonApiException('Empty request body', 400);

		$of = $page->of();
		$page->of(false);

		$saved   = [];
		$skipped = [];

		try {
			foreach ($body as $key => $value) {
				// Immutable / meta keys
				if (in_array($key, ['id', 'name', 'path', 'url', 'template', 'created', 'modified', 'status', 'schema'])) {
					$skipped[] = $key;
					continue;
				}

				$field = wire('fields')->get(wire('sanitizer')->fieldName($key));
				if (!$field || !$page->template->hasField($field)) {
					$skipped[] = $key;
					continue;
				}
				if (!$page->editable($field)) {
					$skipped[] = $key;
					continue;
				}

				$page->set($key, $this->coerceInput($field, $value));
				$saved[] = $key;
			}

			if (empty($saved)) throw new ProcessWireJsonApiException('No editable fields in body', 400);

			$page->save();
		} finally {
			// Restore output formatting regardless of success or exception.
			$page->of($of);
		}

		$this->respond(200, [
			'page'    => $this->formatPageFull($page),
			'saved'   => $saved,
			'skipped' => $skipped,
		]);
	}

	/** POST /api/pw/pages/new  body: { template, parent, name?, title, ...fields } */
	private function pageCreate(): void {
		$body = $this->jsonBody();
		$san  = wire('sanitizer');

		$templateName = $san->pageName($body['template'] ?? '');
		$parentSel    = $san->text($body['parent'] ?? '');
		$title        = $san->text($body['title'] ?? '');
		$name         = $san->pageName($body['name'] ?? '');

		if (!$templateName) throw new ProcessWireJsonApiException('"template" is required', 400);
		if (!$parentSel)    throw new ProcessWireJsonApiException('"parent" is required', 400);

		$template = wire('templates')->get($templateName);
		if (!$template) throw new ProcessWireJsonApiException("Template '$templateName' not found", 404);

		$parent = is_numeric($parentSel)
			? wire('pages')->get((int)$parentSel)
			: wire('pages')->get($parentSel);
		if (!$parent->id) throw new ProcessWireJsonApiException("Parent '$parentSel' not found", 404);

		if (!$parent->addable($template)) throw new ProcessWireJsonApiException('Not allowed to add pages here', 403);

		$page = wire(new Page($template));
		$page->parent = $parent;
		$page->title  = $title;
		$page->name   = $name ?: $san->pageName($title);

		// Extra fields in the body
		foreach ($body as $key => $value) {
			if (in_array($key, ['template', 'parent', 'name', 'title'])) continue;
			$field = wire('fields')->get($san->fieldName($key));
			if (!$field || !$template->hasField($field)) continue;
			$page->set($key, $this->coerceInput($field, $value));
		}

		$page->save();

		$this->respond(201, ['page' => $this->formatPageFull($page)]);
	}

	/** DELETE /api/pw/pages/{id} — moves to trash */
	private function pageDelete(string $id): void {
		$page = $this->resolvePage($id);

		if (!$page->trashable()) throw new ProcessWireJsonApiException('Access denied', 403);

		wire('pages')->trash($page);

		$this->respond(200, ['trashed' => $page->id]);
	}

	// ─────────────────────────────────────────────────────────────────
	// Templates
	// ─────────────────────────────────────────────────────────────────

	private function routeTemplates(string $id, string $method): void {
		if ($method !== 'GET') throw new ProcessWireJsonApiException('Method not allowed', 405);
		$id ? $this->templateGet($id) : $this->templateList();
	}

	/** GET /api/pw/templates */
	private function templateList(): void {
		$out = [];
		foreach (wire('templates') as $t) {
			if ($t->flags & Template::flagSystem) continue;
			$out[] = $this->formatTemplateBrief($t);
		}
		$this->respond(200, ['items' => $out, 'total' => count($out)]);
	}

	/** GET /api/pw/templates/{name} — full schema including all fields */
	private function templateGet(string $name): void {
		$template = wire('templates')->get(wire('sanitizer')->pageName($name));
		if (!$template) throw new ProcessWireJsonApiException("Template '$name' not found", 404);

		$this->respond(200, [
			'template' => $this->formatTemplateFull($template),
		]);
	}

	// ─────────────────────────────────────────────────────────────────
	// Fields
	// ─────────────────────────────────────────────────────────────────

	private function routeFields(string $id, string $method): void {
		if ($method !== 'GET') throw new ProcessWireJsonApiException('Method not allowed', 405);
		$id ? $this->fieldGet($id) : $this->fieldList();
	}

	/** GET /api/pw/fields */
	private function fieldList(): void {
		$out = [];
		foreach (wire('fields') as $f) {
			if ($f->flags & Field::flagSystem) continue;
			$out[] = $this->formatFieldBrief($f);
		}
		$this->respond(200, ['items' => $out, 'total' => count($out)]);
	}

	/** GET /api/pw/fields/{name} */
	private function fieldGet(string $name): void {
		$field = wire('fields')->get(wire('sanitizer')->fieldName($name));
		if (!$field) throw new ProcessWireJsonApiException("Field '$name' not found", 404);
		$this->respond(200, ['field' => $this->formatFieldFull($field)]);
	}

	// ─────────────────────────────────────────────────────────────────
	// Formatters — Pages
	// ─────────────────────────────────────────────────────────────────

	private function formatPageBrief(Page $page): array {
		return [
			'id'       => $page->id,
			'name'     => $page->name,
			'title'    => $page->title,
			'url'      => $page->url,
			'path'     => $page->path,
			'template' => $page->template->name,
			'status'   => $page->status,
			'created'  => date('c', $page->created),
			'modified' => date('c', $page->modified),
			'editable' => $page->editable(),
			'deleteable' => $page->deleteable(),
		];
	}

	private function formatPageFull(Page $page): array {
		$data = $this->formatPageBrief($page);

		$data['parent'] = $page->parent->id
			? ['id' => $page->parent->id, 'name' => $page->parent->name, 'url' => $page->parent->url]
			: null;

		// Output all viewable fields on this page
		foreach ($page->template->fields as $field) {
			if (isset($data[$field->name])) continue;
			$data[$field->name] = $this->formatFieldValue($page, $field);
		}

		return $data;
	}

	// ─────────────────────────────────────────────────────────────────
	// Formatters — Field values (page output)
	// ─────────────────────────────────────────────────────────────────

	private function formatFieldValue(Page $page, Field $field): mixed {
		$value = $page->getFormatted($field->name);
		$type  = $field->type->className();

		return match (true) {
			// Repeaters — must precede the generic PageArray arm because
			// RepeaterPageArray extends PageArray; match() stops at first match.
			$value instanceof PageArray && str_starts_with($type, 'FieldtypeRepeater')
			=> $value->each(fn($rp) => $this->formatPageFull($rp)),

			// Page references
			$value instanceof PageArray => $value->each(fn($p) => [
				'id' => $p->id,
				'name' => $p->name,
				'title' => $p->title,
				'url' => $p->url,
			]),
			$value instanceof Page && $value->id > 0 => [
				'id' => $value->id,
				'name' => $value->name,
				'title' => $value->title,
				'url' => $value->url,
			],

			// Images
			$value instanceof Pageimages => $value->each(fn($img) => $this->formatImage($img)),
			$value instanceof Pageimage  => $this->formatImage($value),

			// Files
			$value instanceof Pagefiles => $value->each(fn($f) => $this->formatFile($f)),
			$value instanceof Pagefile  => $this->formatFile($value),

			// Options
			$value instanceof SelectableOptionArray
			=> $value->each(fn($o) => ['id' => $o->id, 'title' => (string)$o->title, 'value' => $o->value]),

			// MapMarker
			is_object($value) && method_exists($value, 'lat')
			=> ['lat' => $value->lat, 'lng' => $value->lng, 'address' => $value->address ?? ''],

			// WireArray catch-all
			$value instanceof WireArray => $value->getArray(),

			// Scalar
			default => $value,
		};
	}

	private function formatImage(Pageimage $img): array {
		return [
			'url'         => $img->url,
			'basename'    => $img->basename,
			'description' => $img->description,
			'alt'         => $img->description ?: $img->basename,
			'width'       => $img->width,
			'height'      => $img->height,
			'filesize'    => $img->filesize,
			'ext'         => $img->ext,
		];
	}

	private function formatFile(Pagefile $file): array {
		return [
			'url'         => $file->url,
			'basename'    => $file->basename,
			'description' => $file->description,
			'filesize'    => $file->filesize,
			'ext'         => $file->ext,
		];
	}

	// ─────────────────────────────────────────────────────────────────
	// Formatters — Templates
	// ─────────────────────────────────────────────────────────────────

	private function formatTemplateBrief(Template $t): array {
		return [
			'id'         => $t->id,
			'name'       => $t->name,
			'label'      => $t->label ?: $t->name,
			'fieldgroup' => $t->fieldgroup->name,
			'flags'      => $t->flags,
			'noChildren' => (bool)$t->noChildren,
			'noParents'  => $t->noParents,
			'fieldCount' => $t->fields->count(),
		];
	}

	private function formatTemplateFull(Template $t): array {
		$data = $this->formatTemplateBrief($t);
		$data['schema'] = $this->schemaForTemplate($t);

		// Allowed child/parent templates
		$data['allowedChildTemplates']  = $t->childTemplates  ?: [];
		$data['allowedParentTemplates'] = $t->parentTemplates ?: [];

		return $data;
	}

	/**
	 * Full field schema for a template — this is what you use to render
	 * a create/edit form on the front-end, with types, labels, validation hints.
	 */
	private function schemaForTemplate(Template $t): array {
		$schema = [];

		foreach ($t->fields as $field) {
			$schema[] = $this->formatFieldFull($field, $t->fieldgroup->getField($field->name, true));
		}

		return $schema;
	}

	// ─────────────────────────────────────────────────────────────────
	// Formatters — Fields
	// ─────────────────────────────────────────────────────────────────

	private function formatFieldBrief(Field $f): array {
		return [
			'id'    => $f->id,
			'name'  => $f->name,
			'label' => $f->label ?: $f->name,
			'type'  => $f->type->className(),
			'flags' => $f->flags,
		];
	}

	/**
	 * Full field descriptor including everything a front-end form builder needs:
	 * type, label, description, required, collapsed, inputfield type, options,
	 * min/max, allowed file extensions, etc.
	 *
	 * @param Field       $f   The field
	 * @param Field|null  $ctx Context field (from fieldgroup) — carries per-template overrides
	 *                         such as required, collapsed, showIf, columnWidth
	 */
	private function formatFieldFull(Field $f, ?Field $ctx = null): array {
		$type = $f->type->className();

		$data = [
			'id'          => $f->id,
			'name'        => $f->name,
			'label'       => ($ctx?->label ?: $f->label) ?: $f->name,
			'description' => ($ctx?->description ?: $f->description) ?: '',
			'notes'       => ($ctx?->notes ?: $f->notes) ?: '',
			'type'        => $type,
			'inputfield'  => $f->get('inputfieldClass') ?: '',
			'required'    => (bool)(($ctx?->required) ?? $f->required),
			'collapsed'   => (int)(($ctx?->collapsed) ?? $f->collapsed),
			'columnWidth' => (int)(($ctx?->columnWidth) ?: ($f->columnWidth ?: 100)),
			'showIf'      => ($ctx?->showIf ?? $f->showIf) ?: '',
			'requiredIf'  => ($ctx?->requiredIf ?? $f->requiredIf) ?: '',
			'flags'       => $f->flags,
		];

		// ── Type-specific extra hints ──────────────────────────────

		// Text / Textarea
		if (in_array($type, ['FieldtypeText', 'FieldtypeTextarea', 'FieldtypeTextareaLanguage', 'FieldtypeTextLanguage'])) {
			$data['maxlength']   = (int)($f->maxlength ?: 0);
			$data['placeholder'] = $f->placeholder ?: '';
			$data['pattern']     = $f->pattern ?: '';
		}

		// Integer / Float
		if (in_array($type, ['FieldtypeInteger', 'FieldtypeFloat'])) {
			$data['min'] = $f->get('min');
			$data['max'] = $f->get('max');
		}

		// Page reference
		if (in_array($type, ['FieldtypePage', 'FieldtypePageTable'])) {
			$data['derefAsPage']      = (int)$f->derefAsPage;
			$data['inputfield']       = $f->inputfield ?: '';
			$data['parent_id']        = (int)($f->parent_id ?: 0);
			$data['template_id']      = (int)($f->template_id ?: 0);
			$data['findPagesSelector'] = $f->findPagesSelector ?: '';
		}

		// Options
		if ($type === 'FieldtypeOptions') {
			$manager = $this->wire('modules')->get('SelectableOptionManager');
			$options = $manager ? $manager->getOptions($f) : [];
			$data['options'] = [];
			foreach ($options as $opt) {
				$data['options'][] = [
					'id'    => $opt->id,
					'value' => $opt->value,
					'title' => (string)$opt->title,
				];
			}
		}

		// Image / File
		if (in_array($type, ['FieldtypeImage', 'FieldtypeFile', 'FieldtypeImages', 'FieldtypeFiles'])) {
			$data['extensions']   = $f->extensions ?: '';
			$data['maxFiles']     = (int)($f->maxFiles ?: 0);
			$data['minFiles']     = (int)($f->minFiles ?: 0);
			$data['maxFilesize']  = (int)($f->maxFilesize ?: 0);
		}

		// Image extras
		if (in_array($type, ['FieldtypeImage', 'FieldtypeImages'])) {
			$data['maxWidth']  = (int)($f->maxWidth ?: 0);
			$data['maxHeight'] = (int)($f->maxHeight ?: 0);
		}

		// Checkbox
		if ($type === 'FieldtypeCheckbox') {
			$data['checkedValue']   = $f->get('checkedValue') ?: 1;
			$data['uncheckedValue'] = $f->get('uncheckedValue') ?: 0;
		}

		// URL
		if ($type === 'FieldtypeURL') {
			$data['noRelative']  = (bool)$f->noRelative;
			$data['allowIDN']    = (bool)$f->allowIDN;
		}

		// Email
		if ($type === 'FieldtypeEmail') {
			// No extra config, type hint is enough
		}

		// Datetime
		if ($type === 'FieldtypeDatetime') {
			$data['dateInputFormat'] = $f->dateInputFormat ?: '';
			$data['timeInputFormat'] = $f->timeInputFormat ?: '';
		}

		// Repeater — expose child fieldgroup schema
		if (str_starts_with($type, 'FieldtypeRepeater')) {
			$repeaterTemplate = $f->type->getRepeaterTemplate($f);
			if ($repeaterTemplate) {
				$data['repeaterSchema'] = $this->schemaForTemplate($repeaterTemplate);
			}
		}

		return $data;
	}

	// ─────────────────────────────────────────────────────────────────
	// Helpers
	// ─────────────────────────────────────────────────────────────────

	private function resolvePage(string $id): Page {
		$page = is_numeric($id)
			? wire('pages')->get((int)$id)
			: wire('pages')->get('/' . ltrim($id, '/'));

		if (!$page || !$page->id) throw new ProcessWireJsonApiException('Page not found', 404);
		return $page;
	}

	/**
	 * Light coercion of incoming JSON values to types PW expects.
	 * We keep it minimal — PW's own sanitizers run during save().
	 */
	private function coerceInput(Field $field, mixed $value): mixed {
		$type = $field->type->className();

		// Page reference — accept id, array of ids, or array of {id:…} objects
		if (in_array($type, ['FieldtypePage', 'FieldtypePageTable'])) {
			if (is_array($value)) {
				return array_map(fn($v) => is_array($v) ? (int)($v['id'] ?? 0) : (int)$v, $value);
			}
			return (int)$value;
		}

		// Checkbox
		if ($type === 'FieldtypeCheckbox') return (int)(bool)$value;

		// Integer / Float
		if ($type === 'FieldtypeInteger') return (int)$value;
		if ($type === 'FieldtypeFloat')   return (float)$value;

		return $value; // strings, HTML — PW sanitizes during save
	}

	private function jsonBody(): array {
		$raw = file_get_contents('php://input');
		if (!$raw) return [];
		$data = json_decode($raw, true);
		return is_array($data) ? $data : [];
	}

	private function respond(int $status, array $data): void {
		// Discard any output buffers (e.g. Tracy Debugger) that would append HTML to our JSON response
		while (ob_get_level()) ob_end_clean();
		http_response_code($status);
		echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
	}

	// ─────────────────────────────────────────────────────────────────
	// Module config UI
	// ─────────────────────────────────────────────────────────────────

	public static function getModuleConfigInputfields(array $data): InputfieldWrapper {
		$modules = wire('modules');
		$wrap    = new InputfieldWrapper();

		$f = $modules->get('InputfieldText');
		$f->attr('name', 'apiPrefix');
		$f->label       = 'API URL prefix';
		$f->description = 'URL segment used for all routes.';
		$f->notes       = 'e.g. "/api/pw/" → yoursite.com/api/pw/pages';
		$f->value       = $data['apiPrefix'] ?? '/api/pw/';
		$wrap->add($f);

		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', 'requireLogin');
		$f->label       = 'Require login';
		$f->description = 'When enabled, all API endpoints return 401 for unauthenticated requests. Disable to make the API fully public.';
		$f->attr('checked', (int)($data['requireLogin'] ?? 1) ? 'checked' : '');
		$f->value       = 1;
		$wrap->add($f);

		$f = $modules->get('InputfieldMarkup');
		$f->label = 'CORS & API Key Auth';
		$f->value = '<p>CORS settings and API key authentication are configured in the <strong>Auth</strong> module.</p>';
		$wrap->add($f);

		return $wrap;
	}
}

class ProcessWireJsonApiException extends \RuntimeException {
}
