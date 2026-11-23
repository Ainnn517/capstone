"""Most Reported Locations: hovering anywhere on a row shows that place's categories and total."""

import subprocess
from conftest import ENV, PROJECT, log_in
def test_hovering_any_row_of_most_reported_locations_shows_its_details(app, new_page, mail):
    subprocess.run(["php", "artisan", "demo:residents", "--force"], cwd=PROJECT, env=ENV, check=True, capture_output=True)
    page = new_page(1352, 900)
    log_in(page, app, "e2eadmin", mail=mail)
    page.click("[data-admin-view=analytics]")
    page.wait_for_load_state("networkidle"); page.wait_for_timeout(1500)
    canvas = page.locator("#topLocationsChart")
    canvas.scroll_into_view_if_needed(); page.wait_for_timeout(500)
    rows = page.evaluate("""() => { const c = Chart.getChart(document.getElementById('topLocationsChart'));
      return c.data.labels.map((l, i) => ({label: l, y: c.scales.y.getPixelForValue(i), right: c.scales.x.right})); }""")
    box = canvas.bounding_box()
    bad = []
    for i, r in enumerate(rows):
        # Hover far to the right of the bar, where the row is empty: the tooltip must still be for this row.
        page.mouse.move(box["x"] + r["right"] - 20, box["y"] + r["y"])
        page.wait_for_timeout(150)
        tip = page.evaluate("""() => { const t = Chart.getChart(document.getElementById('topLocationsChart')).tooltip;
          return {opacity: t.opacity, title: t.title, n: t.dataPoints.length}; }""")
        if tip["opacity"] == 0 or tip["n"] == 0 or tip["title"][0] != r["label"]:
            bad.append((r["label"], tip))
    assert not bad, bad
    assert len(rows) > 1
