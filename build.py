# يولّد index.html لكل نسخة من الواجهة الموحدة src/frontend.html
import pathlib
root = pathlib.Path(__file__).parent
src = (root / 'src' / 'frontend.html').read_text(encoding='utf-8')
targets = {'python/index.html': 'api', 'php/index.html': 'api.php', 'google-script/Index.html': ''}
for rel, url in targets.items():
    (root / rel).write_text(src.replace('__API_URL__', url), encoding='utf-8')
    print('built', rel)
