project = "WPrint 3D"
author = "WPrint 3D"
copyright = "2026, WPrint 3D"

extensions = [
    "myst_parser",
    "sphinxcontrib.mermaid",
]

templates_path = []
exclude_patterns = ["_build", "Thumbs.db", ".DS_Store"]

source_suffix = {
    ".md": "markdown",
    ".rst": "restructuredtext",
}

myst_enable_extensions = [
    "colon_fence",
]

html_title = "WPrint 3D Documentation"
html_theme = "sphinx_rtd_theme"
html_static_path = []
