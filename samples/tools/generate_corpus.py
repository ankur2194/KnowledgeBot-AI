#!/usr/bin/env python3
"""Generate the ten golden fixture documents from their directory specifications.

Run it in a throwaway container; never install its dependencies into `services/ai-service`.
See `samples/README.md` § *Regenerating the fixtures* for the exact command.

    python tools/generate_corpus.py --out corpus/documents
    python tools/generate_corpus.py --verify        # generate twice, compare every digest

**These files are test instruments, not sample content.** Almost every string below is
load-bearing for a named golden case, and several are load-bearing by being *absent* — the
handbook says nothing about parental leave, the price list has no `KW-2200-C`, the quarterly
deck states no revenue total. Each `corpus/documents/*/README.md` carries a "must NOT contain"
section, and adding a helpful sentence to a fixture does not improve the corpus, it deletes a
test. Read the directory README before editing anything here.

Determinism is enforced, not hoped for: `corpus_lib` pins every clock and `--verify` generates
the whole corpus into two separate directories and compares digests file by file. The scanned
PDF is additionally asserted to carry **no text layer at all**, because a stray text layer would
route it around OCR and silently turn the OCR fixture into an ordinary PDF fixture that passes.
"""

from __future__ import annotations

import argparse
import io
import json
import sys
from pathlib import Path

import docx
import numpy
import openpyxl
import pptx
import pymupdf
from docx.shared import Pt
from openpyxl.styles import Font
from openpyxl.utils import get_column_letter
from PIL import Image, ImageDraw, ImageFilter
from pptx.enum.shapes import MSO_SHAPE
from pptx.util import Inches
from pptx.util import Pt as PptPt

sys.path.insert(0, str(Path(__file__).resolve().parent))

from corpus_lib import (  # noqa: E402
    AUTHOR,
    FIXED_DT,
    PdfDoc,
    image_only_pdf,
    save_ooxml,
    tree_digest,
    write_text,
)

COMPANY = "Kelpwright Instruments"

# The origin the crawl fixture is served from by the Compose `test` profile. A concrete host is
# needed because `robots.txt` has no placeholder syntax; the directory README writes it as
# `<test-origin>`. Flagged to platform-devops-engineer: the static-file service must answer here.
SITE_ORIGIN = "http://corpus-site.internal"


# ═════════════════════════════════════════════════════════════════════════════
# handbook-en-v1.pdf
# ═════════════════════════════════════════════════════════════════════════════
#
# THE TRUNCATION FIXTURE. §3 is one continuous run of prose with the carry-over rule as its LAST
# sentence, deliberately more than 512 tokens after the section heading. Embedding providers cap
# their input window and at least one vendor defaults to trimming the tail rather than erroring,
# so a chunk longer than the window is indexed from its beginning and its end is unsearchable —
# with a 200 response and a plausible vector. Case f-006 asks only about that final sentence, so
# the failure has a name instead of being a diffuse quality dip.

LEAVE_SECTION_BODY = [
    "Employees receive 26 days of annual leave in each calendar year. One additional day is "
    "granted for every five completed years of service, up to a maximum of 30 days. The "
    "entitlement is stated in working days and excludes public holidays observed in Coastland, "
    "which are additional and are published separately by People Operations at the start of "
    "each year.",
    "Leave is booked through the people system and requires the agreement of the line manager "
    "before it is confirmed. Managers are asked to respond to a request within five working "
    "days. Where a request cannot be accommodated, the manager states the operating reason and "
    "works with the employee to find alternative dates. Requests are considered in the order "
    "they are received, and no employee has a standing claim on a particular week.",
    "Employees joining partway through a calendar year receive a proportion of the annual "
    "entitlement calculated on completed months of service, rounded up to the nearest half day. "
    "The same proportional calculation applies to employees leaving partway through a year, and "
    "any balance is settled in the final salary payment. Employees working a reduced week "
    "receive the entitlement pro rata to their contracted hours, calculated on the same basis "
    "and recorded against their contract rather than against the standard week.",
    "Field service teams coordinate leave within their own team calendar so that at least one "
    "engineer qualified on each instrument family remains available. Laboratory and production "
    "teams follow the same principle. Where two requests conflict and no alternative can be "
    "found, the manager escalates to People Operations rather than deciding on length of "
    "service alone.",
    "Leave taken must be recorded in the people system even where it has been agreed verbally. "
    "An unrecorded absence cannot be reconciled at year end, and the reconciliation is what "
    "produces the balance an employee sees. Employees are asked to check their recorded balance "
    "at the end of each quarter and to raise a correction promptly rather than at year end, "
    "when the correction window is short and the evidence is harder to reconstruct.",
    "Where an employee falls ill during a period of booked annual leave, the days affected may "
    "be reclassified as sickness absence and returned to the annual leave balance, subject to "
    "the certification requirements set out in the next subsection. The reclassification is not "
    "automatic and must be requested within one month of returning to work.",
    "Disagreements about a leave balance or a refused request are raised first with the line "
    "manager and then, if unresolved, with People Operations. People Operations holds the "
    "authoritative record and its reconciliation is final for the purposes of payroll.",
    "The company does not operate an unlimited or discretionary leave arrangement, and no "
    "manager may agree an entitlement that differs from the figures stated in this section. "
    "Any local arrangement that appears to do so has been misunderstood and should be referred "
    "to People Operations.",
    "Up to 5 unused days may be carried into the next year, and days carried over in this way "
    "must be used by 31 March.",
]


def build_handbook_en(path: Path) -> str:
    def footer(canvas_obj: object, page: int) -> None:
        canvas_obj.setFont("Helvetica", 7.5)  # type: ignore[attr-defined]
        canvas_obj.drawString(  # type: ignore[attr-defined]
            64.0,
            46.0,
            f"{COMPANY} · Employee Handbook · Revision H · Page {page} of 14",
        )

    doc = PdfDoc(
        path,
        title=f"{COMPANY} — Employee Handbook",
        subject="Employee handbook, synthetic evaluation fixture",
        footer=footer,
    )

    # Title page. The revision letter is deliberately ABSENT here and from every body
    # paragraph; it exists only in the page footer, which is what makes f-005 a content-layer
    # regression test rather than an ordinary lookup.
    doc.spacer(180.0)
    doc.heading(COMPANY, level=0)
    doc.heading("Employee Handbook", level=1)
    doc.spacer(10.0)
    doc.para("Effective 2026-02-01.")
    doc.para(
        "This handbook is a synthetic document written to test a retrieval system. The company, "
        "the people, and every figure in it are invented."
    )
    doc.page_break()

    doc.heading("1. Welcome", level=1)
    doc.para(
        f"{COMPANY} is a fictional company. It designs and manufactures instruments that measure "
        "water quality for utilities, port authorities and environmental agencies. The company "
        "is headquartered in Fairmont, Coastland, with a laboratory and a production line on the "
        "same site."
    )
    doc.para(
        "This handbook sets out the terms that apply to everyone employed by the company. Where "
        "a subject is governed by a separate policy, this handbook points to that policy rather "
        "than repeating it, so that there is one authoritative statement of each rule."
    )

    doc.heading("2. Working hours", level=1)
    doc.para(
        "The standard working week is 38 hours. Core hours are 10:00 to 16:00, Monday to Friday, "
        "and employees are expected to be contactable and available for meetings during that "
        "window."
    )
    doc.para(
        "Time worked outside core hours is flexible and is arranged with the line manager. "
        "Flexibility is a mutual arrangement: it is agreed in advance, it is recorded, and it "
        "does not change the total contracted hours."
    )
    doc.heading("2.1 Remote work", level=2)
    doc.para(
        "Employees may work remotely for up to 2 days per week. Remote working requires manager "
        "approval, and it is not available during the first 3 months of employment."
    )
    doc.para(
        "Remote days are agreed as a recurring pattern rather than requested individually, so "
        "that team coverage is predictable. Roles that require physical access to the "
        "laboratory, the production line, or a customer site are agreed case by case."
    )

    doc.heading("3. Leave", level=1)
    for paragraph in LEAVE_SECTION_BODY:
        doc.para(paragraph)

    doc.heading("3.1 Sickness", level=2)
    doc.para(
        "Absence through illness may be self-certified for up to 3 consecutive days. A medical "
        "certificate is required from day 4 of a continuous absence."
    )
    doc.para(
        "Notify the line manager as early as possible on the first day of absence, and keep them "
        "informed while the absence continues."
    )

    doc.heading("4. Probation and notice", level=1)
    doc.para(
        "Probation is 6 months from the start date. During probation the notice period is "
        "2 weeks on either side. Once probation has been completed, the notice period is 8 weeks "
        "on either side."
    )
    doc.para(
        "A probation period may be extended once, by agreement, where an employee has had a "
        "significant absence during it. An extension is confirmed in writing before the original "
        "period ends."
    )

    doc.heading("5. Equipment", level=1)
    doc.para(
        "Laptops are refreshed every 4 years. A replacement outside that cycle requires a "
        "documented fault or a documented change in role requirements."
    )
    doc.para(
        "Instruments loaned for field work are booked through the service desk. Loaned "
        "instruments are returned to the service desk for calibration checks before they are "
        "issued again."
    )

    doc.heading("6. Expenses", level=1)
    doc.para(
        "Travel and subsistence are governed by the Travel and Expenses Policy. Rates, approval "
        "thresholds and claim deadlines are stated there and are not repeated in this handbook."
    )

    doc.heading("7. Contacts", level=1)
    doc.para("People Operations: people-ops@kelpwright.example")
    doc.para("IT service desk: extension 4120")
    doc.para(
        "Enquiries that do not fit either route go to People Operations, who will direct them. "
        "Individual contact details are held in the people system and are not published in this "
        "handbook."
    )

    def filler(document: PdfDoc) -> None:
        document.heading("Notes", level=2)
        document.para(
            "This page is reserved for local annexes. No entitlement, rate, or obligation is "
            "created by anything written on it."
        )

    doc.pad_to(14, filler)
    digest, pages = doc.save(expect_pages=14)
    assert pages == 14
    return digest


# ═════════════════════════════════════════════════════════════════════════════
# pricing-catalog-v1.xlsx
# ═════════════════════════════════════════════════════════════════════════════

INSTRUMENTS = [
    ("KW-1100-B", "Tideline 1100", "Benchtop analyser, 4 channels", 9200, 4),
    ("KW-2200-B", "Tideline 2200", "Benchtop analyser, 8 channels", 18400, 6),
    ("KW-2200-F", "Tideline 2200F", "Field analyser, 8 channels, IP67", 21750, 9),
    ("KW-3400-B", "Tideline 3400", "Benchtop analyser, 16 channels, autosampler", 31000, 12),
    ("KW-0450-S", "Tideline Sampler 450", "Automatic sampler, 24 bottles", 3150, 3),
]

CONSUMABLES = [
    ("CN-118", "Reagent pack, chloride", "Box of 12", 245),
    ("CN-119", "Reagent pack, nitrate", "Box of 12", 265),
    ("CN-204", "Reference electrode", "Each", 610),
    ("CN-311", "Tubing set, sampler", "Box of 5", 90),
]

SERVICE_PLANS = [
    ("SP-STD", "Standard", 12, "5 business days", 0),
    ("SP-PLUS", "Plus", 18, "Next business day", 2),
    ("SP-CRIT", "Critical", 26, "4 hours remote, next business day on site", 4),
]


def _autosize(sheet: object, headers: list[str], rows: list[tuple[object, ...]]) -> None:
    for index, header in enumerate(headers, start=1):
        longest = max([len(str(header))] + [len(str(row[index - 1])) for row in rows])
        sheet.column_dimensions[get_column_letter(index)].width = min(52, longest + 3)  # type: ignore[attr-defined]


def build_pricing(path: Path) -> str:
    workbook = openpyxl.Workbook()
    workbook.properties.creator = AUTHOR
    workbook.properties.lastModifiedBy = AUTHOR
    workbook.properties.created = FIXED_DT.replace(tzinfo=None)
    workbook.properties.modified = FIXED_DT.replace(tzinfo=None)
    workbook.properties.title = f"{COMPANY} — list prices"

    # Sheet 1. Row 1 is a MERGED TITLE ROW and row 2 is the real header row. A parser that takes
    # row 1 as headers produces a table whose every column is named after one merged cell, which
    # is the specific failure e-001 and t-001 are shaped to expose.
    instruments = workbook.active
    instruments.title = "Instruments"
    headers = ["Part code", "Model", "Description", "List price (EUR)", "Lead time (weeks)"]
    instruments["A1"] = f"{COMPANY} — List prices, effective 2026-01-01"
    instruments.merge_cells(start_row=1, start_column=1, end_row=1, end_column=len(headers))
    instruments["A1"].font = Font(bold=True, size=12)
    instruments.append(headers)
    for cell in instruments[2]:
        cell.font = Font(bold=True)
    for row in INSTRUMENTS:
        instruments.append(list(row))
    instruments.freeze_panes = "A3"
    _autosize(instruments, headers, INSTRUMENTS)

    consumables = workbook.create_sheet("Consumables")
    headers = ["Part code", "Item", "Pack size", "Unit price (EUR)"]
    consumables.append(headers)
    for cell in consumables[1]:
        cell.font = Font(bold=True)
    for row in CONSUMABLES:
        consumables.append(list(row))
    consumables.freeze_panes = "A2"
    _autosize(consumables, headers, CONSUMABLES)

    plans = workbook.create_sheet("Service plans")
    headers = [
        "Plan code",
        "Plan",
        "Annual fee (% of list price)",
        "Response commitment",
        "Calibrations included per year",
    ]
    plans.append(headers)
    for cell in plans[1]:
        cell.font = Font(bold=True)
    for row in SERVICE_PLANS:
        # SP-STD's calibration count is a literal 0, never blank. A zero written as an empty
        # cell reads as "not stated" and t-002 exists to catch exactly that.
        plans.append(list(row))
    plans.freeze_panes = "A2"
    _autosize(plans, headers, SERVICE_PLANS)

    assert len(workbook.sheetnames) == 3, workbook.sheetnames
    return save_ooxml(workbook, path)


# ═════════════════════════════════════════════════════════════════════════════
# quarterly-review-q2-2026.pptx
# ═════════════════════════════════════════════════════════════════════════════
#
# THE SPEAKER-NOTES REGRESSION FIXTURE. Four facts live in ContentLayer.NOTES and nowhere else in
# the corpus. Docling's iterate_items() defaults to BODY only, so a parser regression drops all
# four at once and s-002, s-004, a-003 and u-005 flip together — a self-diagnosing failure.
#
# The "charts" are deliberately empty rectangles rather than real chart parts. A python-pptx
# chart embeds its own xlsx package, with its own zip timestamps that the outer normaliser
# cannot reach, and the fixture's purpose is that the charts carry NO text — an unlabelled shape
# satisfies that exactly, and reproducibly.

SLIDES: list[tuple[str, list[str], bool, str]] = [
    (
        "Q2 2026 Business Review",
        [f"{COMPANY}", "2026-07-09"],
        False,
        "Title slide. Standard quarterly review pack, circulated to the leadership team.",
    ),
    (
        "Agenda",
        ["Service performance", "Regional mix", "Product", "Attach rates", "Looking ahead"],
        False,
        "Five sections, roughly ten minutes each, questions at the end of each section.",
    ),
    (
        "Headcount",
        ["Engineering and service teams grew in Q2", "Recruitment continues in field service"],
        False,
        "Headcount detail is in the people pack; this slide is a summary only.",
    ),
    (
        "Q2 service performance",
        [],
        True,
        "Median first-response time was 3.1 hours in Q2, down from 4.6 hours in Q1.",
    ),
    (
        "Product roadmap",
        ["Firmware 3.2 shipped in June", "Chloride channel now generally available"],
        False,
        "Roadmap items only. Dates beyond the current quarter are indicative and not commitments.",
    ),
    (
        "Field service coverage",
        ["Coverage extended in the northern ports", "Two additional loan instruments in service"],
        False,
        "Coverage is measured by response radius rather than by headcount.",
    ),
    (
        "Regional mix",
        [],
        True,
        "The Coastland region accounted for 38% of Q2 instrument revenue.",
    ),
    (
        "Customer feedback",
        ["Survey response rate improved", "Calibration turnaround is the most common request"],
        False,
        "Verbatim feedback is in the appendix pack, which is not circulated outside the team.",
    ),
    (
        "Attach rates",
        ["Service attach continues to improve"],
        True,
        "The SP-PLUS attach rate reached 41% of new instrument sales in Q2.",
    ),
    (
        "Operations",
        ["Lead times held steady across the instrument range", "No supplier escalations in Q2"],
        False,
        "Lead times are published in the price list and were unchanged this quarter.",
    ),
    (
        "Looking ahead",
        ["Continue service investment", "Extend field coverage", "Improve calibration turnaround"],
        False,
        "We will not be publishing FY2027 targets until the board meeting in November 2026.",
    ),
    (
        "Summary",
        ["Service performance improved", "Attach rates improved", "Coverage extended"],
        False,
        "Close with questions. The full pack is filed with the quarterly archive.",
    ),
]


def build_quarterly(path: Path) -> str:
    presentation = pptx.Presentation()
    core = presentation.core_properties
    core.author = AUTHOR
    core.last_modified_by = AUTHOR
    core.created = FIXED_DT.replace(tzinfo=None)
    core.modified = FIXED_DT.replace(tzinfo=None)
    core.title = "Q2 2026 Business Review"
    core.revision = 1

    title_only = presentation.slide_layouts[5]
    for title, bullets, has_chart, note in SLIDES:
        slide = presentation.slides.add_slide(title_only)
        slide.shapes.title.text = title
        if bullets:
            box = slide.shapes.add_textbox(Inches(0.9), Inches(1.9), Inches(8.0), Inches(4.0))
            frame = box.text_frame
            frame.word_wrap = True
            for index, line in enumerate(bullets):
                paragraph = frame.paragraphs[0] if index == 0 else frame.add_paragraph()
                paragraph.text = line
                paragraph.font.size = PptPt(18)
        if has_chart:
            # An unlabelled placeholder. No text, no data labels, no axis titles — the facts for
            # these slides are in the notes and must not be reachable from the slide body.
            slide.shapes.add_shape(
                MSO_SHAPE.RECTANGLE, Inches(1.2), Inches(2.1), Inches(7.4), Inches(3.6)
            )
        slide.notes_slide.notes_text_frame.text = note

    assert len(presentation.slides) == 12, len(presentation.slides)
    return save_ooxml(presentation, path)


# ═════════════════════════════════════════════════════════════════════════════
# DOCX fixtures
# ═════════════════════════════════════════════════════════════════════════════


def _new_docx(title: str) -> docx.document.Document:
    document = docx.Document()
    core = document.core_properties
    core.author = AUTHOR
    core.last_modified_by = AUTHOR
    core.created = FIXED_DT.replace(tzinfo=None)
    core.modified = FIXED_DT.replace(tzinfo=None)
    core.title = title
    core.revision = 1
    style = document.styles["Normal"]
    style.font.name = "Calibri"
    style.font.size = Pt(11)
    return document


def build_travel_policy(path: Path) -> str:
    document = _new_docx("Travel and Expenses Policy — Version 3")
    document.add_heading("Travel and Expenses Policy — Version 3", level=0)
    document.add_paragraph("Effective 2026-04-01. Supersedes the policy dated 2025-01-15.")
    document.add_paragraph(
        "Next scheduled review: 2028-04-01 (this policy is reviewed every 24 months)."
    )

    document.add_heading("1. Scope", level=1)
    document.add_paragraph(
        "This policy applies to all employees and to contractors travelling at Kelpwright's "
        "request. It governs travel, subsistence and the reimbursement of related costs."
    )

    document.add_heading("2. Approval", level=1)
    document.add_paragraph(
        "Advance written approval is required for any single trip whose total estimated cost "
        "exceeds 1,200 EUR. The estimate includes transport, accommodation and subsistence."
    )
    document.add_paragraph(
        "Approval is given by the line manager and is recorded before the booking is made. A "
        "booking made before approval is at the traveller's own risk."
    )

    document.add_heading("3. Air travel", level=1)
    document.add_paragraph(
        "Economy class is mandatory where the scheduled flight time is under 6 hours. Premium "
        "economy is permitted where the scheduled flight time is 6 hours or more. Business class "
        "requires an executive exception."
    )

    document.add_heading("4. Subsistence (per diem)", level=1)
    document.add_paragraph(
        "Effective 2026-04-01, the subsistence rates are 62 EUR per day for domestic travel and "
        "88 EUR per day for international travel. A half rate applies on the day of departure "
        "and on the day of return."
    )
    document.add_paragraph(
        "Subsistence is a flat allowance. Receipts are not required for it, and meals provided "
        "as part of a conference or by a customer are deducted from the day's allowance."
    )

    document.add_heading("5. Private vehicle", level=1)
    document.add_paragraph(
        "Use of a private vehicle is reimbursed at 0.34 EUR per kilometre, effective 2026-04-01. "
        "The distance claimed is the distance actually driven for the business purpose."
    )

    document.add_heading("6. Claims", level=1)
    document.add_paragraph(
        "Claims must be submitted within 30 days of the trip end date. Claims submitted later "
        "than that require both line-manager and finance approval; they are not automatically "
        "refused."
    )

    document.add_heading("7. Accommodation", level=1)
    document.add_paragraph(
        "Accommodation is booked through the corporate travel agent. Travellers select from the "
        "options the agent offers for the destination and dates."
    )

    # Appendix A states its date range in its OWN BODY TEXT, not only in the heading. The chunker
    # is free to split at a heading, and a chunk carrying the numbers without the qualifier is a
    # chunk the bot can cite honestly and answer wrongly — which is d-002's entire subject.
    document.add_heading("Appendix A — Superseded rates", level=1)
    document.add_paragraph(
        "Rates applicable to travel completed before 2026-04-01 (policy dated 2025-01-15). The "
        "rates in this appendix apply only to travel completed before 2026-04-01 and are "
        "superseded for all travel completed on or after that date."
    )
    document.add_paragraph(
        "Under the policy dated 2025-01-15, subsistence was 55 EUR per day for domestic travel "
        "and 80 EUR per day for international travel, and private vehicle use was reimbursed at "
        "0.31 EUR per kilometre. These figures apply only to travel completed before 2026-04-01."
    )
    return save_ooxml(document, path)


def build_warranty_terms(path: Path) -> str:
    document = _new_docx("Warranty Terms 2026")
    document.add_heading(f"{COMPANY} — Warranty Terms", level=0)
    document.add_paragraph("Effective 2026-01-01.")

    document.add_heading("1. Warranty period", level=1)
    document.add_paragraph(
        "Effective 2026-01-01, the standard warranty for Tideline benchtop instruments is "
        "36 months from the date of commissioning."
    )
    document.add_paragraph(
        "Commissioning means the date on which the instrument is installed and accepted at the "
        "customer's site, as recorded on the commissioning report."
    )

    document.add_heading("2. Support response", level=1)
    document.add_paragraph(
        "Support response commitments are set by the service plan purchased. Instruments without "
        "a service plan receive best-effort support."
    )

    document.add_heading("3. Transition", level=1)
    # DELIBERATELY INCOMPLETE. It covers instruments commissioned on or after 2026-01-01 and says
    # nothing about instruments shipped in 2025 and commissioned in 2026. That silence is c-004,
    # the only case in the suite where "the sources do not settle it" is correct while both
    # sources are highly relevant. Do not add a resolving sentence here.
    document.add_paragraph(
        "These terms apply to Tideline benchtop instruments commissioned on or after 2026-01-01."
    )

    document.add_heading("4. What the warranty covers", level=1)
    document.add_paragraph(
        "The warranty covers defects in materials and workmanship in the instrument as supplied. "
        "Repair or replacement is at Kelpwright's option, and a repaired instrument continues "
        "under the remainder of the original period."
    )

    document.add_heading("5. Claims", level=1)
    document.add_paragraph(
        "A warranty claim is raised through the support channel published on the company website. "
        "An RMA number must be issued before an instrument is returned."
    )
    return save_ooxml(document, path)


def build_handbook_de(path: Path) -> str:
    document = _new_docx("Mitarbeiterhandbuch — deutsche Ausgabe")
    document.add_heading(f"{COMPANY} — Mitarbeiterhandbuch", level=0)
    document.add_paragraph("Deutsche Ausgabe. Auszug. Gültig ab 2026-02-01.")

    document.add_heading("2. Arbeitszeit", level=1)
    document.add_paragraph(
        "Die regelmäßige Arbeitswoche beträgt 38 Stunden. Die Kernzeit liegt von 10:00 bis 16:00 "
        "Uhr, Montag bis Freitag."
    )
    document.add_paragraph(
        "Arbeitszeit außerhalb der Kernzeit wird flexibel und in Absprache mit der "
        "Führungskraft gestaltet. Die vertraglich vereinbarte Gesamtstundenzahl bleibt "
        "unverändert."
    )

    document.add_heading("2.1 Mobiles Arbeiten", level=2)
    document.add_paragraph(
        "Mobiles Arbeiten ist an bis zu 2 Tagen pro Woche möglich. Es bedarf der Zustimmung der "
        "Führungskraft und ist in den ersten 3 Monaten der Beschäftigung nicht möglich."
    )

    document.add_heading("3. Urlaub", level=1)
    document.add_paragraph(
        "Mitarbeitende erhalten 26 Urlaubstage pro Kalenderjahr. Für je fünf abgeschlossene "
        "Dienstjahre kommt ein weiterer Tag hinzu, höchstens jedoch 30 Tage."
    )
    document.add_paragraph(
        "Urlaub wird über das Personalsystem beantragt und von der Führungskraft genehmigt. "
        "Bis zu 5 nicht genommene Tage können in das Folgejahr übertragen werden und sind bis "
        "zum 31. März zu nehmen."
    )

    # THE CROSS-LINGUAL CASE (m-003). This appendix exists in no English document, and an English
    # question about it contains none of its terms — "works council" never appears, only
    # "Betriebsrat" — so the sparse branch contributes nothing and the case rides entirely on the
    # dense embedding's cross-lingual alignment. No English text in this section, including the
    # heading.
    document.add_heading("Anhang DE — Betriebsrat", level=1)
    document.add_paragraph(
        "Der Betriebsrat tagt am zweiten Dienstag jedes Monats. Anträge sind spätestens eine "
        "Woche vorher einzureichen."
    )
    document.add_paragraph(
        "Die Sitzungen finden am Standort Fairmont statt. Die Tagesordnung wird vorab im "
        "Personalsystem veröffentlicht."
    )
    return save_ooxml(document, path)


def build_faq_es(path: Path) -> str:
    document = _new_docx("Preguntas frecuentes de soporte")
    document.add_heading(f"{COMPANY} — Preguntas frecuentes de soporte", level=0)
    document.add_paragraph("Edición en español. Documento sintético de prueba.")

    document.add_heading("Calibración", level=1)
    document.add_paragraph(
        "El intervalo de calibración recomendado es de 12 meses. Los instrumentos en uso "
        "continuo en campo deben calibrarse cada 6 meses."
    )

    document.add_heading("Devoluciones (RMA)", level=1)
    document.add_paragraph(
        "Las solicitudes de RMA deben presentarse en un plazo de 14 días desde la detección del "
        "fallo. Debe emitirse un número de RMA antes de enviar un instrumento de vuelta."
    )
    return save_ooxml(document, path)


# ═════════════════════════════════════════════════════════════════════════════
# warranty-datasheet-2025.pdf
# ═════════════════════════════════════════════════════════════════════════════


def build_warranty_datasheet(path: Path) -> str:
    def footer(canvas_obj: object, page: int) -> None:
        canvas_obj.setFont("Helvetica", 7.5)  # type: ignore[attr-defined]
        canvas_obj.drawString(  # type: ignore[attr-defined]
            64.0, 46.0, f"{COMPANY} · Tideline benchtop datasheet · March 2025 · "
            f"Page {page} of 2"
        )

    doc = PdfDoc(
        path,
        title="Tideline benchtop instruments — datasheet (March 2025)",
        subject="Product datasheet, synthetic evaluation fixture",
        footer=footer,
    )
    doc.heading(f"{COMPANY}", level=0)
    doc.heading("Tideline benchtop instruments — datasheet", level=1)
    doc.para("March 2025")
    doc.spacer(6.0)
    doc.para(
        "The Tideline benchtop range measures water quality in laboratory and depot settings. "
        "All models share the same sample handling, the same reagent packs and the same service "
        "interface."
    )

    doc.heading("Warranty and support", level=1)
    doc.para(
        "All Tideline benchtop instruments carry a 24-month warranty from the date of shipment."
    )
    doc.para(
        "Next-business-day support response is included as standard with every instrument."
    )

    doc.heading("Specifications", level=1)
    doc.bullet("Tideline 1100 — 4 measurement channels, benchtop enclosure.")
    doc.bullet("Tideline 2200 — 8 measurement channels, benchtop enclosure.")
    doc.bullet("Tideline 3400 — 16 measurement channels with integrated autosampler.")
    doc.bullet("Sample volume: 20 mL minimum per determination.")
    doc.bullet("Operating range: 5 to 40 degrees Celsius, non-condensing.")

    doc.page_break()
    doc.heading("Installation and power", level=1)
    doc.para(
        "Instruments are supplied ready for benchtop installation. A stable bench, a mains "
        "outlet and a waste container are required."
    )
    doc.bullet("Power: 230 V, 50 Hz, single phase.")
    doc.bullet("Typical consumption: 180 W in measurement, 40 W idle.")
    doc.bullet("Dimensions: 480 mm wide, 420 mm deep, 350 mm high.")
    doc.bullet("Mass: 18 kg (1100), 24 kg (2200), 31 kg (3400).")

    # DELIBERATELY SILENT ON CONSUMABLES. fu-004 asks, after a successful warranty turn, whether
    # the warranty covers consumables, and the correct answer is a refusal — so no document in
    # the corpus may say that consumables are covered OR that they are excluded. An earlier draft
    # of this section mentioned reagent packs; it was removed, because a sentence about ordering
    # consumables inside a warranty-bearing datasheet is exactly the evidence a model completes
    # into "consumables are excluded", which is what most warranties say and what this corpus
    # must not let it get away with.
    doc.heading("Calibration", level=1)
    doc.para(
        "Calibration is performed against reference solutions supplied by the customer or by "
        "Kelpwright. Calibration records are stored on the instrument and exported through the "
        "service interface."
    )

    doc.heading("Ordering", level=1)
    doc.para(
        "Part codes and list prices are published in the current price list. Lead times vary by "
        "model and are confirmed at order acknowledgement."
    )
    digest, pages = doc.save(expect_pages=2)
    assert pages == 2
    return digest


# ═════════════════════════════════════════════════════════════════════════════
# scanned-po-88214.pdf — image-only, page 3 deliberately degraded
# ═════════════════════════════════════════════════════════════════════════════


def _po_source_pdf() -> bytes:
    buffer = Path("/tmp/_po_source.pdf")
    doc = PdfDoc(
        buffer,
        title="Purchase order PO-88214",
        subject="Purchase order, synthetic evaluation fixture",
    )
    doc.heading("Harbourline Water Authority", level=0)
    doc.heading("Purchase order", level=1)
    doc.para("PO number: PO-88214")
    doc.para("Date: 2026-02-11")
    doc.para("Buyer: Harbourline Water Authority")
    doc.para("Buyer reference: HWA-CAPEX-26-004")
    doc.para(f"Supplier: {COMPANY}, Fairmont, Coastland")
    doc.spacer(8.0)
    doc.heading("Payment terms", level=2)
    doc.para("Payment is due 30 days from the date of a valid invoice.")
    doc.para("Invoices must quote the purchase order number and the buyer reference.")
    doc.page_break()

    doc.heading("Line items", level=1)
    doc.para("Item 1")
    doc.bullet("Part: KW-1100-B")
    doc.bullet("Description: Tideline 1100 benchtop analyser, 4 channels")
    doc.bullet("Quantity: 2")
    doc.bullet("Unit price: 9,200 EUR")
    doc.bullet("Line total: 18,400 EUR")
    doc.spacer(8.0)
    doc.heading("Delivery address", level=2)
    doc.para("Dock 7, Harbourline Depot, Fairmont")
    doc.para("Deliveries accepted 08:00 to 16:00, Monday to Friday.")
    doc.page_break()

    doc.heading("Terms and conditions", level=1)
    doc.para(
        "This order is placed subject to the buyer's standard conditions of purchase. Acceptance "
        "of this order constitutes acceptance of those conditions in full. No variation is "
        "effective unless confirmed in writing by the buyer's procurement function."
    )
    doc.para(
        "Goods must be delivered in the quantities and to the specification stated. The buyer "
        "may reject goods that do not conform and may require their removal at the supplier's "
        "cost. Title and risk pass on acceptance at the delivery address."
    )
    doc.para(
        "The supplier shall indemnify the buyer against claims arising from defective goods. "
        "Nothing in this order limits liability for death or personal injury caused by "
        "negligence."
    )
    doc.spacer(6.0)
    doc.heading("Delivery address (repeated)", level=2)
    doc.para("Dock 7, Harbourline Depot, Fairmont")
    doc.page_break()

    doc.heading("Authorisation", level=1)
    doc.para("Authorised by: A. Quill, Procurement Lead")
    doc.para("Date: 2026-02-11")
    doc.spacer(20.0)
    doc.para("Signature: ______________________________")
    doc.save(expect_pages=4)
    data = buffer.read_bytes()
    buffer.unlink()
    return data


# ── page 3 degradation, TUNED AGAINST A MEASURED OCR ENGINE ──────────────────
#
# Every number below was measured, not chosen. The directory README's original recipe (35% ink,
# JPEG quality 18) was written before a modern engine was available to test it against, and
# rapidocr 3.9.2 / PP-OCRv6 reads that page at **mean confidence 0.9879 with 116% coverage** —
# it is not a degraded page as far as the OCR stack is concerned. Per that README's own rule,
# the fix is more degradation, not a lower threshold.
#
# WHAT THE SWEEP FOUND, AND THE FINDING THAT CAME OUT OF IT
# --------------------------------------------------------
# Confidence and coverage do NOT degrade together on this engine. Holding JPEG, blur and noise
# and sweeping ink alone:
#
#     ink   mean_conf  coverage  boxes
#     0.170   0.8587    0.5447     13
#     0.160   0.8215    0.4137     10
#     0.155   0.8141    0.2859      7
#     0.150   0.8291    0.1965      5     <- shipped
#     0.140   0.8826    0.1102      3
#     0.125   0.9911    0.0415      2
#
# Mean confidence never goes below ~0.81 while any text is still detected, and below that the
# detector proposes nothing at all and the mean is 0.0 over an empty set. **Coverage collapses
# roughly three times faster than confidence.**
#
# CORRECTED 2026-08-07 — the sweep above is unchanged and correct, the conclusion drawn from it
# was not. This comment used to say the mean was floored because the recognition head's softmax
# saturates, and therefore that the joint target was unreachable and a mean-based warning could
# never fire. The floor is real; the explanation was wrong, and so was the verdict.
#
# Measured through the real entry point: the loss is AT DETECTION. The detector proposes fewer
# regions as the page degrades (15 -> 6 -> 3 -> 1 -> 0) and nothing downstream removes them —
# on this fixture, detector count, post-empty-drop count and post-text_score count are equal on
# every page, so the `boxes` column above is all three at once. Degradation on this engine is
# expressed as ABSENCE, not as low confidence, and the selection happens before recognition
# runs, so no statistic over recogniser confidence can undo it.
#
# The shipped signals are therefore character mass below a per-cell trust floor, and ink-in-box
# coverage — and BOTH fire on this unchanged page (mass 0.6161, ink-in-box 0.2070, floors 0.30).
# The joint target is met and always was. Full record: corpus/documents/scanned/README.md.
# Do not degrade the page further on the strength of the superseded text above.
#
# The per-box distribution is usable too — at the shipped setting, 3 of 5 boxes read below 0.8
# and the worst reads 0.69.
#
# Shipped point chosen for MARGIN, not for proximity to a threshold: coverage 0.1965 sits ~35%
# below the 0.30 line, so an engine upgrade that reads somewhat better does not flip the fixture.
# 0.155 was rejected precisely because 0.2859 is a coin flip against 0.30.

DEGRADE_INK = 0.150
DEGRADE_JPEG_QUALITY = 10
DEGRADE_BLUR_RADIUS = 1.7
DEGRADE_NOISE_SIGMA = 20.0
DEGRADE_SKEW_DEGREES = 3.0
DEGRADE_NOISE_SEED = 20260101


def _degrade(image: Image.Image) -> Image.Image:
    """Degrade a page the way a bad scan is degraded — not by blurring uniformly.

    Order matters. The carbon-copy ghost is laid down before the skew so it rotates with the
    page; the ink fade comes after the skew so the interpolated edges fade with everything else;
    the JPEG pass runs last so it quantises all of it. Reordering these changes the measured
    numbers in the table above, so re-measure if you touch it.

    The noise generator is seeded. `Image.effect_noise` is not seedable and would make the
    fixture's digest change on every run, which is the one thing this corpus cannot tolerate.
    """
    page = image.convert("L")

    # Carbon-copy ghost: a faint offset duplicate, as a two-part form leaves behind.
    ghost = page.point(lambda value: int(255 - (255 - value) * 0.15))
    base = Image.new("L", page.size, 255)
    base.paste(ghost, (9, 5))
    page = Image.composite(page, base, page.point(lambda value: 255 if value < 200 else 0))

    # 3° skew — above Leptonica's 0.1° detection floor, so it exercises deskew.
    page = page.rotate(
        DEGRADE_SKEW_DEGREES, resample=Image.BICUBIC, expand=False, fillcolor=255
    )

    # Low contrast: grey on grey, ink at 15% of full black.
    page = page.point(lambda value: int(255 - (255 - value) * DEGRADE_INK))

    draw = ImageDraw.Draw(page)
    fold = int(page.size[1] * 0.42)
    draw.line([(0, fold), (page.size[0], fold)], fill=140, width=3)

    page = page.filter(ImageFilter.GaussianBlur(DEGRADE_BLUR_RADIUS))

    pixels = numpy.array(page).astype(numpy.int16)
    generator = numpy.random.default_rng(DEGRADE_NOISE_SEED)
    noisy = generator.normal(0.0, DEGRADE_NOISE_SIGMA, pixels.shape)
    page = Image.fromarray(
        numpy.clip(pixels + noisy, 0, 255).astype(numpy.uint8), "L"
    )

    buffer = io.BytesIO()
    page.save(buffer, format="JPEG", quality=DEGRADE_JPEG_QUALITY, optimize=False)
    return Image.open(io.BytesIO(buffer.getvalue())).convert("L")


def build_scanned_po(path: Path, *, dpi: int = 300) -> str:
    source = pymupdf.open(stream=_po_source_pdf(), filetype="pdf")
    assert source.page_count == 4, source.page_count

    pages: list[bytes] = []
    for index in range(source.page_count):
        pixmap = source[index].get_pixmap(dpi=dpi)
        image = Image.frombytes("RGB", (pixmap.width, pixmap.height), pixmap.samples)
        if index == 2:  # page 3, 0-indexed
            image = _degrade(image)
        buffer = io.BytesIO()
        image.convert("L").save(buffer, format="PNG", optimize=True)
        pages.append(buffer.getvalue())
    source.close()

    digest = image_only_pdf(path, pages, dpi=dpi)

    # THE ASSERTION THAT MAKES THIS FIXTURE AN OCR FIXTURE. A PDF with a text layer skips OCR
    # entirely, the scanned-document case silently becomes the plain-PDF case, and the OCR path
    # has no coverage at all while appearing to.
    check = pymupdf.open(path)
    assert check.page_count == 4, check.page_count
    for index in range(4):
        text = check[index].get_text().strip()
        if text:
            raise AssertionError(
                f"{path.name} page {index + 1} carries a text layer ({text[:60]!r}). An "
                "image-only PDF is the whole point of this fixture — it must require OCR."
            )
    check.close()
    return digest


# ═════════════════════════════════════════════════════════════════════════════
# site-kelpwright-www — static HTML tree
# ═════════════════════════════════════════════════════════════════════════════

SITE_FOOTER = (
    "  <footer>\n"
    "    <p>Kelpwright Instruments is a fictional company. All content on this site is "
    "synthetic and exists only to test retrieval.</p>\n"
    "{extra}"
    "  </footer>\n"
)


def _page(title: str, body: str, *, footer_extra: str = "") -> str:
    footer = SITE_FOOTER.format(extra=footer_extra)
    return (
        "<!DOCTYPE html>\n"
        '<html lang="en">\n'
        "<head>\n"
        '  <meta charset="utf-8">\n'
        f"  <title>{title}</title>\n"
        "</head>\n"
        "<body>\n"
        f"{body}"
        f"{footer}"
        "</body>\n"
        "</html>"
    )


SITE_PAGES: dict[str, str] = {
    "index.html": _page(
        f"{COMPANY}",
        "  <h1>Kelpwright Instruments</h1>\n"
        "  <p>Kelpwright Instruments designs and manufactures water quality analysers for "
        "utilities, port authorities and environmental agencies.</p>\n"
        "  <ul>\n"
        '    <li><a href="/products/index.html">Products</a></li>\n'
        '    <li><a href="/support/index.html">Support</a></li>\n'
        '    <li><a href="/about/contact.html">Contact</a></li>\n'
        '    <li><a href="/legal/terms.html">Website terms</a></li>\n'
        '    <li><a href="/news/2026-06-firmware-3-2.html">News</a></li>\n'
        "  </ul>\n",
    ),
    "products/index.html": _page(
        "Products",
        "  <h1>Products</h1>\n"
        "  <p>The Tideline family covers benchtop and field measurement.</p>\n"
        "  <ul>\n"
        "    <li>Tideline 1100 — benchtop analyser</li>\n"
        '    <li><a href="/products/tideline-2200.html">Tideline 2200</a> — benchtop '
        "analyser</li>\n"
        "    <li>Tideline 2200F — field analyser</li>\n"
        "    <li>Tideline 3400 — benchtop analyser with autosampler</li>\n"
        "    <li>Tideline Sampler 450 — automatic sampler</li>\n"
        "  </ul>\n",
    ),
    "products/tideline-2200.html": _page(
        "Tideline 2200",
        "  <h1>Tideline 2200</h1>\n"
        "  <p>The Tideline 2200 is an eight-channel benchtop analyser.</p>\n"
        "  <p>The Tideline 2200F is the field variant of the same platform. It carries an IP67 "
        "rating and the same eight measurement channels.</p>\n"
        "  <p>Full specifications are published in the product datasheet.</p>\n",
    ),
    "support/index.html": _page(
        "Support",
        "  <h1>Support</h1>\n"
        "  <p>Support enquiries are handled by email. Please include the instrument serial "
        "number and the site.</p>\n"
        "  <ul>\n"
        '    <li><a href="/support/calibration.html">Calibration</a></li>\n'
        '    <li><a href="/support/rma.html">Returns and RMA</a></li>\n'
        "  </ul>\n",
        # THE FOOTER-ONLY FACT. Support hours appear here and in the body of no document in the
        # corpus. A conservative HTML-to-Markdown pass that strips <footer> as boilerplate
        # deletes the only statement of them.
        footer_extra=(
            "    <p>Support is staffed 08:00–18:00 Coastland time, Monday to Friday.</p>\n"
        ),
    ),
    "support/calibration.html": _page(
        "Calibration",
        "  <h1>Calibration</h1>\n"
        "  <p>The recommended calibration interval is 12 months. Instruments in continuous field "
        "use should be calibrated every 6 months.</p>\n"
        "  <p>Calibration may be performed on site or at the Fairmont laboratory.</p>\n",
    ),
    "support/rma.html": _page(
        "Returns and RMA",
        "  <h1>Returns and RMA</h1>\n"
        "  <p>RMA requests must be raised within 14 days of fault discovery. An RMA number must "
        "be issued before an instrument is shipped back.</p>\n"
        "  <p>Instruments returned without an RMA number cannot be booked in.</p>\n",
    ),
    "about/contact.html": _page(
        "Contact",
        "  <h1>Contact</h1>\n"
        "  <p>Kelpwright Instruments<br>\n"
        "  14 Saltmarsh Way<br>\n"
        "  Fairmont<br>\n"
        "  Coastland</p>\n"
        "  <p>Email: support@kelpwright.example</p>\n",
    ),
    "legal/terms.html": _page(
        "Website terms",
        "  <h1>Website terms</h1>\n"
        "  <p>These terms govern the use of this website. They are not the terms of sale and "
        "they are not warranty terms.</p>\n"
        "  <p>Content on this site is provided for information and may change without "
        "notice.</p>\n",
    ),
    "news/2026-06-firmware-3-2.html": _page(
        "Firmware 3.2",
        "  <h1>Firmware 3.2</h1>\n"
        "  <p>Firmware 3.2 was released on 2026-06-18. It adds the chloride channel and requires "
        "bootloader 1.4 or later.</p>\n"
        "  <p>The update is applied through the service interface.</p>\n",
    ),
    # DISALLOWED by robots.txt and linked from nowhere. A correct crawler never fetches it, so
    # the 22% figure never enters the index and u-006 has no answer. If u-006 ever returns 22%,
    # that is a crawl-scope violation and not a quality regression.
    "internal/staging.html": _page(
        "Staging",
        "  <h1>Staging</h1>\n"
        "  <p>Partner pricing: authorised partners receive a 22% discount on list prices.</p>\n",
    ),
}

CRAWLABLE = [name for name in SITE_PAGES if not name.startswith("internal/")]

SITEMAP_LASTMOD = {
    "index.html": "2026-06-20",
    "products/index.html": "2026-05-12",
    "products/tideline-2200.html": "2026-05-12",
    "support/index.html": "2026-06-02",
    "support/calibration.html": "2026-04-18",
    "support/rma.html": "2026-04-18",
    "about/contact.html": "2026-03-30",
    "legal/terms.html": "2026-01-09",
    "news/2026-06-firmware-3-2.html": "2026-06-18",
}


def build_site(root: Path) -> tuple[str, int]:
    for name, content in SITE_PAGES.items():
        write_text(root / name, content)

    write_text(
        root / "robots.txt",
        "User-agent: *\nDisallow: /internal/\n\nSitemap: " + SITE_ORIGIN + "/sitemap.xml",
    )

    entries = "".join(
        f"  <url>\n    <loc>{SITE_ORIGIN}/{name}</loc>\n"
        f"    <lastmod>{SITEMAP_LASTMOD[name]}</lastmod>\n  </url>\n"
        for name in sorted(CRAWLABLE)
    )
    write_text(
        root / "sitemap.xml",
        '<?xml version="1.0" encoding="UTF-8"?>\n'
        '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n'
        f"{entries}</urlset>",
    )

    assert len(CRAWLABLE) == 9, CRAWLABLE
    return tree_digest(root)


# ═════════════════════════════════════════════════════════════════════════════
# Orchestration
# ═════════════════════════════════════════════════════════════════════════════


def generate(documents_root: Path) -> dict[str, str]:
    """Write every fixture under `documents_root` and return manifest id -> sha256."""
    digests: dict[str, str] = {}
    digests["handbook-en-v1"] = build_handbook_en(documents_root / "handbook/handbook-en-v1.pdf")
    digests["pricing-catalog-v1"] = build_pricing(
        documents_root / "pricing/pricing-catalog-v1.xlsx"
    )
    digests["quarterly-review-q2-2026"] = build_quarterly(
        documents_root / "quarterly/quarterly-review-q2-2026.pptx"
    )
    digests["travel-policy-v3"] = build_travel_policy(
        documents_root / "policy/travel-policy-v3.docx"
    )
    digests["scanned-po-88214"] = build_scanned_po(
        documents_root / "scanned/scanned-po-88214.pdf"
    )
    site_digest, site_files = build_site(documents_root / "site/www")
    digests["site-kelpwright-www"] = site_digest
    digests["warranty-datasheet-2025"] = build_warranty_datasheet(
        documents_root / "conflicting/warranty-datasheet-2025.pdf"
    )
    digests["warranty-terms-2026"] = build_warranty_terms(
        documents_root / "conflicting/warranty-terms-2026.docx"
    )
    digests["handbook-de-v1"] = build_handbook_de(
        documents_root / "multilingual/handbook-de-v1.docx"
    )
    digests["faq-es-v1"] = build_faq_es(documents_root / "multilingual/faq-es-v1.docx")

    assert len(digests) == 10, sorted(digests)
    assert site_files == 12, site_files
    return digests


def _token_estimate(text: str) -> int:
    """A deliberately crude over-estimate of English token count, ~1.3 per word."""
    return int(len(text.split()) * 1.3)


def check_truncation_fixture() -> int:
    """§3 must put its final rule past the 512-token mark, or f-006 tests nothing."""
    before_last = " ".join(LEAVE_SECTION_BODY[:-1])
    tokens = _token_estimate(before_last)
    if tokens <= 560:
        raise AssertionError(
            f"handbook §3 carries only ~{tokens} tokens before its final sentence. The "
            "carry-over rule must sit beyond a 512-token window or f-006 cannot detect a "
            "silently truncated embedding input."
        )
    return tokens


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--out", type=Path, help="documents root to write into")
    parser.add_argument(
        "--verify",
        action="store_true",
        help="generate twice into separate directories and compare every digest",
    )
    parser.add_argument("--scratch", type=Path, default=Path("/tmp/corpus-verify"))
    args = parser.parse_args()

    tokens = check_truncation_fixture()
    print(f"handbook §3 carries ~{tokens} estimated tokens before its final rule", file=sys.stderr)

    if args.verify:
        first = generate(args.scratch / "a")
        second = generate(args.scratch / "b")
        drifting = sorted(key for key in first if first[key] != second[key])
        if drifting:
            print("NON-DETERMINISTIC:", drifting, file=sys.stderr)
            for key in drifting:
                print(f"  {key}: {first[key][:16]} != {second[key][:16]}", file=sys.stderr)
            return 1
        print(f"deterministic: {len(first)} fixtures identical across two runs", file=sys.stderr)
        if args.out is None:
            print(json.dumps(first, indent=2))
            return 0

    if args.out is None:
        parser.error("--out is required unless only --verify is wanted")
    digests = generate(args.out)
    print(json.dumps(digests, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
