from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, quote, unquote, urlparse
import html
import os
import shutil
import zipfile


ROOT = Path(__file__).resolve().parents[1]
TEXT_EXTENSIONS = {
    ".css", ".csv", ".html", ".htm", ".js", ".json", ".log", ".md", ".php",
    ".py", ".svg", ".txt", ".xml", ".yml", ".yaml",
}


def resolve_inside(raw_path: str) -> Path:
    raw_path = unquote(raw_path or "").replace("\\", "/").lstrip("/")
    candidate = (ROOT / raw_path).resolve()
    if candidate != ROOT and ROOT not in candidate.parents:
        raise ValueError("Path outside project")
    return candidate


def relative_path(path: Path) -> str:
    return "" if path.resolve() == ROOT else path.resolve().relative_to(ROOT).as_posix()


def url_for(path: Path) -> str:
    return quote(relative_path(path))


def human_size(path: Path) -> str:
    if path.is_dir():
        return "-"
    size = path.stat().st_size
    for unit in ["B", "KB", "MB", "GB"]:
        if size < 1024:
            return f"{size:.0f} {unit}" if unit == "B" else f"{size:.1f} {unit}"
        size /= 1024
    return f"{size:.1f} TB"


def is_text_file(path: Path) -> bool:
    return path.is_file() and (path.suffix.lower() in TEXT_EXTENSIONS or path.stat().st_size < 64_000)


class LocalPanel(BaseHTTPRequestHandler):
    server_version = "HostPanelLocal/2.0"

    def do_GET(self):
        parsed = urlparse(self.path)
        if parsed.path == "/download":
            self.download_file(parsed)
            return
        if parsed.path == "/extract":
            self.extract_zip(parsed)
            return
        if parsed.path == "/edit":
            self.render_editor(parsed)
            return
        self.render_panel(parsed)

    def do_POST(self):
        parsed = urlparse(self.path)
        routes = {
            "/upload": self.upload_file,
            "/create-folder": self.create_folder,
            "/create-file": self.create_file,
            "/rename": self.rename_item,
            "/delete": self.delete_item,
            "/save": self.save_file,
        }
        handler = routes.get(parsed.path)
        if not handler:
            self.send_error(404)
            return
        handler(parsed)

    def render_panel(self, parsed):
        current = self.current_dir(parsed)
        message = parse_qs(parsed.query).get("message", [""])[0]
        search = parse_qs(parsed.query).get("q", [""])[0].strip().lower()
        items = list(current.iterdir())
        if search:
            items = [item for item in items if search in item.name.lower()]
        items.sort(key=lambda p: (not p.is_dir(), p.name.lower()))

        rows = []
        if current != ROOT:
            rows.append(self.row_for(current.parent, label="..", is_parent=True))
        rows.extend(self.row_for(item) for item in items)

        body = self.layout(
            title="File Manager",
            current=current,
            message=message,
            content=f"""
            <section class="hero">
              <div>
                <p class="eyebrow">Gerenciador de Arquivos</p>
                <h1>Arquivos do projeto</h1>
                <p class="muted">Controle local inspirado no hPanel: upload, extracao, edicao e organizacao dentro desta pasta.</p>
              </div>
              <a class="primary" href="http://127.0.0.1:8000/" target="_blank">Abrir Preview</a>
            </section>

            <section class="quick-grid">
              <form class="quick-card" action="/upload?path={quote(relative_path(current))}" method="post" enctype="multipart/form-data">
                <strong>Upload</strong>
                <span>Envie arquivos para a pasta atual.</span>
                <input type="file" name="file">
                <button type="submit">Enviar arquivo</button>
              </form>
              <form class="quick-card" action="/create-folder" method="post">
                <strong>Nova pasta</strong>
                <span>Crie diretorios para organizar assets.</span>
                <input type="hidden" name="path" value="{html.escape(relative_path(current))}">
                <input name="name" placeholder="nome-da-pasta">
                <button type="submit">Criar pasta</button>
              </form>
              <form class="quick-card" action="/create-file" method="post">
                <strong>Novo arquivo</strong>
                <span>Crie HTML, CSS, JS ou TXT vazio.</span>
                <input type="hidden" name="path" value="{html.escape(relative_path(current))}">
                <input name="name" placeholder="arquivo.html">
                <button type="submit">Criar arquivo</button>
              </form>
            </section>

            <section class="panel">
              <div class="panel-head">
                <div>
                  <h2>File Manager</h2>
                  <p>{len(items)} item(ns) nesta visualizacao</p>
                </div>
                <form class="search" action="/" method="get">
                  <input type="hidden" name="path" value="{html.escape(relative_path(current))}">
                  <input name="q" value="{html.escape(search)}" placeholder="Buscar nesta pasta">
                  <button type="submit">Buscar</button>
                </form>
              </div>
              <div class="table-wrap">
                <table>
                  <thead>
                    <tr><th>Nome</th><th>Tipo</th><th>Tamanho</th><th>Modificado</th><th>Acoes</th></tr>
                  </thead>
                  <tbody>{''.join(rows) or '<tr><td colspan="5" class="empty">Nenhum arquivo encontrado.</td></tr>'}</tbody>
                </table>
              </div>
            </section>
            """,
        )
        self.respond_html(body)

    def row_for(self, item: Path, label=None, is_parent=False) -> str:
        item_rel = url_for(item)
        safe_name = html.escape(label or item.name)
        modified = "" if is_parent else html.escape(__import__("datetime").datetime.fromtimestamp(item.stat().st_mtime).strftime("%d/%m/%Y %H:%M"))
        if item.is_dir():
            name = f'<a class="file-name folder" href="/?path={item_rel}">{safe_name}/</a>'
            kind = "Pasta"
            actions = "" if is_parent else self.actions_for(item)
        else:
            name = f'<a class="file-name" href="/download?path={item_rel}">{safe_name}</a>'
            kind = item.suffix.lower().lstrip(".").upper() or "Arquivo"
            actions = self.actions_for(item)
        return f"""
        <tr>
          <td>{name}</td>
          <td>{kind}</td>
          <td>{human_size(item)}</td>
          <td>{modified}</td>
          <td class="actions">{actions}</td>
        </tr>"""

    def actions_for(self, item: Path) -> str:
        item_rel = url_for(item)
        rename_form = f"""
        <form action="/rename" method="post" class="inline-form">
          <input type="hidden" name="path" value="{html.escape(relative_path(item))}">
          <input name="name" value="{html.escape(item.name)}" aria-label="Novo nome">
          <button type="submit">Renomear</button>
        </form>"""
        delete_form = f"""
        <form action="/delete" method="post" class="inline-form" onsubmit="return confirm('Apagar {html.escape(item.name)}?');">
          <input type="hidden" name="path" value="{html.escape(relative_path(item))}">
          <button class="danger" type="submit">Apagar</button>
        </form>"""
        links = [f'<a href="/download?path={item_rel}">Baixar</a>' if item.is_file() else f'<a href="/?path={item_rel}">Abrir</a>']
        if item.is_file() and is_text_file(item):
            links.append(f'<a href="/edit?path={item_rel}">Editar</a>')
        if item.is_file() and item.suffix.lower() == ".zip":
            links.append(f'<a href="/extract?path={item_rel}">Extrair</a>')
        return f'<div class="action-links">{"".join(links)}</div>{rename_form}{delete_form}'

    def render_editor(self, parsed):
        try:
            path = resolve_inside(parse_qs(parsed.query).get("path", [""])[0])
        except ValueError:
            self.send_error(400)
            return
        if not is_text_file(path):
            self.send_error(400)
            return
        try:
            content = path.read_text(encoding="utf-8")
        except UnicodeDecodeError:
            content = path.read_text(encoding="latin-1")
        body = self.layout(
            title=f"Editar {path.name}",
            current=path.parent,
            message="",
            content=f"""
            <section class="panel editor-panel">
              <div class="panel-head">
                <div>
                  <h2>Editor de arquivo</h2>
                  <p>{html.escape(relative_path(path))}</p>
                </div>
                <a class="secondary" href="/?path={quote(relative_path(path.parent))}">Voltar</a>
              </div>
              <form action="/save" method="post">
                <input type="hidden" name="path" value="{html.escape(relative_path(path))}">
                <textarea name="content" spellcheck="false">{html.escape(content)}</textarea>
                <div class="editor-actions">
                  <button type="submit">Salvar alteracoes</button>
                  <a class="secondary" href="/download?path={quote(relative_path(path))}">Baixar</a>
                </div>
              </form>
            </section>
            """,
        )
        self.respond_html(body)

    def layout(self, title: str, current: Path, message: str, content: str) -> str:
        breadcrumb = self.breadcrumb(current)
        msg = f'<div class="notice">{html.escape(message)}</div>' if message else ""
        return f"""<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{html.escape(title)} - Local Host Panel</title>
  <style>
    :root {{
      --ink: #1f2937;
      --muted: #6b7280;
      --line: #e5e7eb;
      --soft: #f6f7fb;
      --panel: #ffffff;
      --brand: #673de6;
      --brand-dark: #5025d1;
      --accent: #00b090;
      --danger: #d92d20;
    }}
    * {{ box-sizing: border-box; }}
    body {{ margin: 0; font-family: Inter, Arial, sans-serif; color: var(--ink); background: var(--soft); }}
    .app {{ display: grid; grid-template-columns: 248px 1fr; min-height: 100vh; }}
    aside {{ background: #fff; border-right: 1px solid var(--line); padding: 22px 18px; position: sticky; top: 0; height: 100vh; }}
    .brand {{ display: flex; align-items: center; gap: 10px; font-weight: 800; font-size: 19px; margin-bottom: 28px; }}
    .brand-mark {{ width: 32px; height: 32px; border-radius: 8px; background: var(--brand); color: #fff; display: grid; place-items: center; }}
    nav a {{ display: block; padding: 11px 12px; border-radius: 8px; color: #374151; text-decoration: none; margin-bottom: 6px; font-weight: 600; }}
    nav a.active, nav a:hover {{ background: #f0ecff; color: var(--brand-dark); }}
    .side-title {{ color: var(--muted); font-size: 12px; text-transform: uppercase; margin: 24px 12px 8px; font-weight: 800; }}
    main {{ padding: 24px 30px 42px; min-width: 0; }}
    .topbar {{ display: flex; justify-content: space-between; gap: 18px; align-items: center; margin-bottom: 18px; }}
    .crumbs {{ font-size: 14px; color: var(--muted); }}
    .crumbs a {{ color: var(--brand-dark); text-decoration: none; font-weight: 700; }}
    .hero {{ display: flex; justify-content: space-between; align-items: center; gap: 20px; background: linear-gradient(135deg, #ffffff, #f8fbff); border: 1px solid var(--line); border-radius: 12px; padding: 24px; margin-bottom: 18px; }}
    h1, h2, p {{ margin-top: 0; }}
    h1 {{ margin-bottom: 6px; font-size: 28px; }}
    h2 {{ margin-bottom: 4px; font-size: 19px; }}
    .eyebrow {{ color: var(--brand-dark); font-weight: 800; text-transform: uppercase; font-size: 12px; margin-bottom: 8px; }}
    .muted, .panel-head p, .quick-card span {{ color: var(--muted); }}
    .primary, button {{ background: var(--brand); color: white; border: 0; border-radius: 8px; padding: 10px 14px; text-decoration: none; font-weight: 800; cursor: pointer; }}
    .primary:hover, button:hover {{ background: var(--brand-dark); }}
    .secondary {{ border: 1px solid var(--line); color: var(--ink); background: #fff; border-radius: 8px; padding: 10px 14px; text-decoration: none; font-weight: 800; }}
    .quick-grid {{ display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; margin-bottom: 18px; }}
    .quick-card, .panel {{ background: var(--panel); border: 1px solid var(--line); border-radius: 12px; box-shadow: 0 8px 22px rgba(17, 24, 39, .04); }}
    .quick-card {{ padding: 16px; display: grid; gap: 10px; align-content: start; }}
    input, textarea {{ width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 10px 11px; font: inherit; background: #fff; }}
    .panel {{ overflow: hidden; }}
    .panel-head {{ display: flex; justify-content: space-between; gap: 14px; align-items: center; padding: 18px 20px; border-bottom: 1px solid var(--line); }}
    .search {{ display: flex; gap: 8px; min-width: 340px; }}
    .table-wrap {{ overflow-x: auto; }}
    table {{ width: 100%; border-collapse: collapse; }}
    th, td {{ padding: 13px 14px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }}
    th {{ background: #fafafa; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: 0; }}
    .file-name {{ color: var(--ink); text-decoration: none; font-weight: 800; }}
    .file-name:hover {{ color: var(--brand-dark); }}
    .folder::before {{ content: "[DIR] "; color: var(--accent); }}
    .action-links {{ display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 8px; }}
    .action-links a {{ color: var(--brand-dark); font-weight: 800; text-decoration: none; }}
    .inline-form {{ display: inline-flex; gap: 6px; margin: 0 6px 6px 0; align-items: center; }}
    .inline-form input {{ width: 150px; padding: 7px 8px; }}
    .inline-form button {{ padding: 7px 9px; border-radius: 7px; }}
    .danger {{ background: var(--danger); }}
    .danger:hover {{ background: #b42318; }}
    .notice {{ border-left: 4px solid var(--accent); background: #ecfdf8; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-weight: 700; }}
    .empty {{ text-align: center; color: var(--muted); padding: 34px; }}
    textarea {{ min-height: 560px; font-family: Consolas, monospace; line-height: 1.45; }}
    .editor-actions {{ display: flex; gap: 10px; padding: 16px 0 0; }}
    .editor-panel form {{ padding: 18px 20px 20px; }}
    @media (max-width: 920px) {{
      .app {{ grid-template-columns: 1fr; }}
      aside {{ position: static; height: auto; }}
      .quick-grid {{ grid-template-columns: 1fr; }}
      .hero, .panel-head, .topbar {{ align-items: stretch; flex-direction: column; }}
      .search {{ min-width: 0; }}
    }}
  </style>
</head>
<body>
  <div class="app">
    <aside>
      <div class="brand"><span class="brand-mark">H</span><span>Host Panel</span></div>
      <nav>
        <a class="active" href="/">File Manager</a>
        <a href="http://127.0.0.1:8000/" target="_blank">Preview</a>
        <a href="/?path=images">Images</a>
        <a href="/?path=fonts">Fonts</a>
        <a href="/?path=js">Scripts</a>
      </nav>
      <div class="side-title">Projeto</div>
      <nav>
        <a href="/edit?path=index.html">Editar index.html</a>
        <a href="/download?path=index.html">Baixar index.html</a>
      </nav>
    </aside>
    <main>
      <div class="topbar">
        <div>{breadcrumb}</div>
        <div class="muted">{html.escape(str(ROOT))}</div>
      </div>
      {msg}
      {content}
    </main>
  </div>
</body>
</html>"""

    def breadcrumb(self, current: Path) -> str:
        parts = ['<span class="crumbs"><a href="/">public_html</a>']
        rel = relative_path(current)
        if rel:
            accumulator = []
            for part in rel.split("/"):
                accumulator.append(part)
                parts.append(f' / <a href="/?path={quote("/".join(accumulator))}">{html.escape(part)}</a>')
        parts.append("</span>")
        return "".join(parts)

    def current_dir(self, parsed) -> Path:
        try:
            current = resolve_inside(parse_qs(parsed.query).get("path", [""])[0])
        except ValueError:
            return ROOT
        return current if current.exists() and current.is_dir() else ROOT

    def form_data(self) -> dict[str, str]:
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length).decode("utf-8", "replace")
        return {key: values[0] for key, values in parse_qs(body, keep_blank_values=True).items()}

    def upload_file(self, parsed):
        target_dir = self.current_dir(parsed)
        upload = self.read_upload()
        if upload:
            safe_name, payload = upload
            out_path = (target_dir / safe_name).resolve()
            if out_path == ROOT or ROOT in out_path.parents:
                out_path.write_bytes(payload)
        self.redirect_to_dir(target_dir, "Upload concluido.")

    def create_folder(self, parsed):
        data = self.form_data()
        current = resolve_inside(data.get("path", ""))
        name = Path(data.get("name", "").strip()).name
        if name and current.is_dir():
            (current / name).mkdir(exist_ok=True)
        self.redirect_to_dir(current, "Pasta criada.")

    def create_file(self, parsed):
        data = self.form_data()
        current = resolve_inside(data.get("path", ""))
        name = Path(data.get("name", "").strip()).name
        if name and current.is_dir():
            target = (current / name).resolve()
            if target == ROOT or ROOT in target.parents:
                target.touch(exist_ok=True)
        self.redirect_to_dir(current, "Arquivo criado.")

    def rename_item(self, parsed):
        data = self.form_data()
        path = resolve_inside(data.get("path", ""))
        new_name = Path(data.get("name", "").strip()).name
        parent = path.parent if path != ROOT else ROOT
        if new_name and path.exists() and path != ROOT:
            path.rename(parent / new_name)
        self.redirect_to_dir(parent, "Item renomeado.")

    def delete_item(self, parsed):
        data = self.form_data()
        path = resolve_inside(data.get("path", ""))
        parent = path.parent if path != ROOT else ROOT
        if path.exists() and path != ROOT:
            if path.is_dir():
                shutil.rmtree(path)
            else:
                path.unlink()
        self.redirect_to_dir(parent, "Item apagado.")

    def save_file(self, parsed):
        data = self.form_data()
        path = resolve_inside(data.get("path", ""))
        if is_text_file(path):
            path.write_text(data.get("content", ""), encoding="utf-8")
            self.redirect(f"/edit?path={quote(relative_path(path))}&message=Arquivo%20salvo")
            return
        self.send_error(400)

    def read_upload(self):
        content_type = self.headers.get("Content-Type", "")
        if "multipart/form-data" not in content_type or "boundary=" not in content_type:
            return None
        boundary = content_type.split("boundary=", 1)[1].strip().strip('"')
        length = int(self.headers.get("Content-Length", "0"))
        body = self.rfile.read(length)
        marker = ("--" + boundary).encode("utf-8")
        for part in body.split(marker):
            if b'Content-Disposition:' not in part or b'name="file"' not in part:
                continue
            header, _, payload = part.partition(b"\r\n\r\n")
            filename = ""
            for chunk in header.decode("utf-8", "ignore").split(";"):
                chunk = chunk.strip()
                if chunk.startswith("filename="):
                    filename = chunk.split("=", 1)[1].strip().strip('"')
                    break
            if filename:
                return Path(filename).name, payload.rstrip(b"\r\n-")
        return None

    def download_file(self, parsed):
        try:
            path = resolve_inside(parse_qs(parsed.query).get("path", [""])[0])
        except ValueError:
            self.send_error(400)
            return
        if not path.is_file():
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header("Content-Type", "application/octet-stream")
        self.send_header("Content-Disposition", f'attachment; filename="{path.name}"')
        self.send_header("Content-Length", str(path.stat().st_size))
        self.end_headers()
        with path.open("rb") as source:
            shutil.copyfileobj(source, self.wfile)

    def extract_zip(self, parsed):
        try:
            path = resolve_inside(parse_qs(parsed.query).get("path", [""])[0])
        except ValueError:
            self.send_error(400)
            return
        if not path.is_file() or path.suffix.lower() != ".zip":
            self.send_error(400)
            return
        target = path.with_suffix("")
        target.mkdir(exist_ok=True)
        with zipfile.ZipFile(path) as archive:
            for member in archive.infolist():
                member_path = (target / member.filename).resolve()
                if member_path == target or target in member_path.parents:
                    archive.extract(member, target)
        self.redirect_to_dir(target, "ZIP extraido.")

    def redirect_to_dir(self, path: Path, message: str):
        directory = path if path.is_dir() else path.parent
        self.redirect(f"/?path={quote(relative_path(directory))}&message={quote(message)}")

    def redirect(self, location):
        self.send_response(303)
        self.send_header("Location", location)
        self.end_headers()

    def respond_html(self, body: str):
        payload = body.encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "text/html; charset=utf-8")
        self.send_header("Content-Length", str(len(payload)))
        self.end_headers()
        self.wfile.write(payload)


if __name__ == "__main__":
    port = int(os.environ.get("LOCAL_PANEL_PORT", "8080"))
    server = ThreadingHTTPServer(("127.0.0.1", port), LocalPanel)
    print(f"Local Host Panel: http://127.0.0.1:{port}/")
    server.serve_forever()
