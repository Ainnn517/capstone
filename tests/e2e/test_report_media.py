"""A resident attaches several photos and a video; both sides can view them."""

import base64
import os
import re
import shutil
import subprocess
import tempfile

from playwright.sync_api import expect

from conftest import log_in, open_resident_view

PNG = base64.b64decode("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=")


def _real_mp4() -> bytes:
    """A real one-second clip: the server runs uploads through ffmpeg, which refuses fake files.
    Falls back to a minimal MP4 header when ffmpeg is not installed (the server then stores it as is)."""
    with tempfile.TemporaryDirectory() as folder:
        path = os.path.join(folder, "clip.mp4")
        result = subprocess.run(
            ["ffmpeg", "-v", "error", "-y", "-f", "lavfi", "-i", "testsrc=size=64x48:rate=10", "-t", "1", "-c:v", "mpeg4", path],
            capture_output=True,
        ) if shutil.which("ffmpeg") else None
        if result is not None and result.returncode == 0:
            with open(path, "rb") as handle:
                return handle.read()
    return b"\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom" + b"\x00" * 2048


MP4 = _real_mp4()


def test_photos_and_video_reach_the_admin(app, new_page, mail):
    resident = new_page()
    log_in(resident, app, "e2eresident")
    resident.select_option("#category", label="Road Damage")
    resident.select_option("#phase", index=1)
    if resident.locator("#purok").is_enabled():
        resident.select_option("#purok", index=1)
    resident.fill("#location", "Near the chapel")
    resident.fill("#description", "Deep pothole in the middle of the road near the corner.")

    # Too many photos is caught before sending.
    resident.set_input_files("#image", [{"name": f"p{i}.png", "mimeType": "image/png", "buffer": PNG} for i in range(6)])
    expect(resident.locator("#mediaError")).to_have_text("Choose at most 5 photos.")

    resident.set_input_files("#image", [{"name": f"p{i}.png", "mimeType": "image/png", "buffer": PNG} for i in range(2)])
    resident.set_input_files("#video", {"name": "clip.mp4", "mimeType": "video/mp4", "buffer": MP4})
    expect(resident.locator("#mediaError")).to_have_text("")
    expect(resident.locator("#mediaPreview img")).to_have_count(2)
    expect(resident.locator("#mediaPreview video")).to_have_count(1)
    resident.click("#issueForm button[type=submit]")
    expect(resident.locator("#submitAlert")).to_contain_text("Report Submitted")
    expect(resident.locator("#mediaPreview img")).to_have_count(0)

    open_resident_view(resident, "reports")
    card = resident.locator(".issue-card").first
    expect(card.locator(".issue-media img")).to_have_count(2)
    expect(card.locator(".issue-media video")).to_have_count(1)

    admin = new_page()
    log_in(admin, app, "e2eadmin", mail=mail)
    admin.click("[data-admin-view=incidents]")
    row = admin.locator("#incidentBody tr", has_text="Road Damage")
    expect(row).to_contain_text("View 2 photos")
    expect(row).to_contain_text("Play video")

    # Both links open the full-screen viewer on the same page; Back returns to the table.
    row.get_by_text("View 2 photos").click()
    viewer = admin.locator(".photo-viewer")
    expect(viewer).to_be_visible()
    expect(viewer.locator(".photo-viewer-img")).to_have_attribute("src", re.compile(r"^/api/attachments/\d+$"))
    expect(viewer.locator(".photo-viewer-count")).to_have_text("1 / 2")
    viewer.locator(".photo-viewer-back").click()
    expect(viewer).to_be_hidden()

    row.get_by_text("Play video").click()
    expect(viewer).to_be_visible()
    expect(viewer.locator(".photo-viewer-video")).to_have_attribute("src", re.compile(r"^/api/attachments/\d+$"))
    expect(viewer.locator(".photo-viewer-img")).to_be_hidden()
    viewer.locator(".photo-viewer-back").click()
    expect(viewer).to_be_hidden()
    expect(admin).to_have_url(re.compile(r"/admin"))
    row.locator("[data-report-id]").click()
    expect(admin.locator("#actionModal .admin-media img")).to_have_count(2)
    expect(admin.locator("#actionModal .admin-media video")).to_have_count(1)
