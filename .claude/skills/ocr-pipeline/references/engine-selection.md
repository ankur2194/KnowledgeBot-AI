# OCR engine comparison — reference

Depth for `ocr-pipeline`. The decision and its four load-bearing arguments live in `SKILL.md`; this file carries the candidate-by-candidate evidence so the choice can be re-audited without re-researching it.

**Verification method.** Every version and date below was read from the **PyPI JSON API** (`https://pypi.org/pypi/<pkg>/json`) or the **GitHub releases API**, on 2026-08-04. Rendered documentation pages mis-render several 2026 release dates as 2024 — Docling 2.118.0 and Tesseract 5.5.3 both appear as 2024 in HTML. Distrust the browser, trust the API. Wheel sizes are the `manylinux`/`x86_64` artifacts from the same JSON. **Container image sizes are not published by any of these projects** and are not claimed here.

## The candidates

| Engine | Version | Released | Licence — code | Licence — weights | Python runtime weight | Docling backend | Confidence | Verdict |
|---|---|---|---|---|---|---|---|---|
| **RapidOCR** (PP-OCR, ONNX) | 3.9.2 | 2026-07-21 | Apache-2.0 | see "the licence chain" below | wheel 27.3 MB + `onnxruntime` 19.2 MB ≈ **47 MB** | ✅ `rapidocr` | line **and** word, 0–1 | **default** |
| **Tesseract** / tesserocr | 5.5.3 / 2.11.0 | 2026-07-24 / 2026-08-04 | Apache-2.0 | Apache-2.0 (explicit) | binary + tessdata, no ML runtime | ✅ `tesserocr` + `tesseract` CLI | word, **0–100** | **alternative** (multi-script) |
| EasyOCR | 1.7.2 | **2024-09-24** | Apache-2.0 | **unstated** | `torch` **502 MB** | ✅ `easyocr` | line, 0–1 | rejected |
| PaddleOCR | 3.7.0 | 2026-06-11 | Apache-2.0 | Apache-2.0, per-model on HF | `paddlepaddle` **194.8 MB** | ✗ | line, 0–1 | rejected |
| Surya | 0.22.1 | 2026-07-20 | Apache-2.0 (relicensed from GPL-3.0, 2026-05-14) | **modified AI Pubs OpenRAIL-M** | 650M VLM; needs vLLM or llama.cpp | ✗ | block only, 0–1 | **disqualified** |
| docTR | 1.0.1 | 2026-02-04 | Apache-2.0 | **unstated** (Mindee CDN, no terms) | `torch` | ✗ | word | rejected |
| Nemotron OCR | via Docling | — | — | NVIDIA <!-- UNVERIFIED: terms not read --> | CUDA only | ✅ `nemotron-ocr` | — | **not deployable** — ADR-030 removed the `gpu` profile |
| KServe v2 | via Docling | — | — | — | remote inference | ✅ `kserve_v2_ocr` | — | **banned** |

Also surveyed and rejected without a table row: MinerU (custom Apache-derived code licence with an attribution mandate for online services; 2.5 weights AGPL-3.0), olmOCR (≥12 GB NVIDIA VRAM), dots.mocr and DeepSeek-OCR 2 (vLLM / hard flash-attn pin), Granite-Docling 258M (~102 s/page under Transformers), Qwen3-VL (GPU).

## Why each rejection

- **EasyOCR** — no release in ~2 years, weights carry no stated licence, and it drags 502 MB of Torch for accuracy measurably below PP-OCR (below). Docling still lists it, which is exactly why it must not be reachable by accident through `OcrAutoOptions`.
- **PaddleOCR** — not a Docling backend at all, and RapidOCR already serves the same PP-OCR graphs through a 4× smaller runtime. Choosing PaddleOCR means adding a second inference framework to get the same models.
- **Surya** — two independent disqualifiers. (1) The weights remain **modified AI Pubs OpenRAIL-M**: free only for organizations under a **$5M** funding/revenue threshold, plus a clause 2(c) competing-product ban carrying *no* revenue floor and a share-alike that arguably reaches OCR **output**. KnowledgeBot is self-hosted by tenants we do not vet and cannot license on their behalf. (2) Surya 2 is no longer a Torch model — it is a 650M VLM requiring a vLLM container or a llama.cpp server, and the only published non-NVIDIA figure is Apple Metal at **0.108 pages/s ≈ 9.3 s/page**. No x86 CPU number exists. <br>⚠ **Datalab's own sources disagree**: the README and `MODEL_LICENSE` say Apache-2.0 code and a $5M cap, while the [on-prem docs](https://documentation.datalab.to/docs/on-prem/overview) still say "GPL + custom RAILs" and **< $2M ARR/funding**. If anyone ever proposes reopening Surya, get that discrepancy resolved in writing first.
- **docTR** — no Docling backend, Torch-weight, and its weights ship from `doctr-static.mindee.com` with no licence terms attached.
- **Nemotron OCR** — `NemotronOcrModel.validate_runtime()` requires Linux **and** x86_64 **and** `sys.version_info[:2] == (3, 12)` **and** an available CUDA device (read from source; Docling's prose docs add CUDA **13.x**). It was only ever justifiable behind the optional `gpu` profile, and **ADR-030 deleted that profile** — there is no GPU in this deployment. Not an option; it is listed so the next person does not re-derive it. Note the hard Python 3.12 pin would also fail our 3.13 floor.
- **KServe v2** — a remote-inference backend. Our parser container runs with no network namespace by policy (`kb-security-baseline`), so this can only ever fail, and it must be named in the startup assertion so it cannot be selected.
- **Nanonets-OCR-s / OCR2-3B** — Docling ships a VLM preset, making these reachable from our own stack, but the HF cards carry **no licence field** and Nanonets staff confirmed in repo discussions that they inherit the **Qwen Research License, which is non-commercial**. Hard no.

## The licence chain for our default

RapidOCR's own README states: *"The copyright of the OCR model is held by Baidu, while the copyrights of all other engineering scripts are retained by the repository's owner. This project is released under the Apache 2.0 license."*

So RapidOCR's `LICENSE` covers its engineering code, and the **permission to redistribute and use the weights comes from PaddlePaddle's per-model Apache-2.0 cards on Hugging Face**, not from RapidOCR. That resolves cleanly — PP-OCR models are published Apache-2.0 — but cite the PaddlePaddle model card, not RapidOCR's LICENSE, if anyone asks for the provenance. There is no revenue clause anywhere in the chain, which is the whole point of choosing it over Surya.

## Accuracy evidence

**OmniDocBench text-OCR track** (edit distance, lower is better) — the only public benchmark that puts our three CPU candidates on one axis:

| | English | Chinese |
|---|---|---|
| PaddleOCR (= RapidOCR's models) | **0.071** | **0.055** |
| Tesseract | 0.096 | **0.551** |
| EasyOCR | 0.26 | 0.398 |

PP-OCR wins on English and beats Tesseract **10×** on Chinese. docTR and RapidOCR-as-such are not evaluated; RapidOCR is treated as equivalent to PaddleOCR because it runs the same exported graphs. <!-- UNVERIFIED: that equivalence is an inference from RapidOCR shipping PP-OCR exports, not a measurement. -->

**OmniDocBench v1.6 end-to-end** (`Overall = ((1−TextEdit)×100 + TableTEDS + FormulaCDM)/3`, higher better): PaddleOCR-VL-1.6 **96.34** · MinerU2.5-Pro 95.75 · dots.ocr 90.77 · DeepSeek-OCR 2 90.25 · Qwen3-VL-235B 89.78 · olmOCR-7B 85.74. Read the shape, not just the ranking: a sub-1B specialist beats a 235B general VLM by 6.5 points, so "throw a bigger VLM at it" is not the upgrade path.

**Latency** — PP-OCRv6 on an Intel Xeon 8350C with OpenVINO, seconds per image end to end: **v6_tiny 0.20 · v6_small 0.59 · v6_medium 1.40 · v5_server 7.30**. This is the only usable CPU throughput figure any of these projects publishes, and it is comfortably inside §19.4's 30 s/page budget.

⚠ **PP-OCRv6's headline accuracy claims are not comparable to v5's**, by PaddleOCR's own admission: *"PP-OCRv6 metrics are evaluated on an internal multi-scenario evaluation set, while PP-OCRv5/v4 metrics are based on a general evaluation set… the metrics are not directly comparable."* Do not quote the "+5.1% recognition" figure.

⚠ **The OmniDocBench dataset is research-licensed.** Quoting its published numbers is fine; running it inside a commercial evaluation harness is not. Relevant to docs/16-evaluation.md.

## Model-revision trap: PP-OCRv6 language coverage

Docling 2.118's RapidOCR backend supports PP-OCR **v4, v5 and v6**. v6 unifies **50 languages in one model** — which removes the single-language limitation for Latin and CJK corpora — but **drops from v5's 106**, losing Arabic, Cyrillic, Devanagari, Thai, Greek, Korean, Tamil and Telugu. For those scripts "upgrade to v6" is a regression: stay on v5's per-script models, or route the tenant to tesserocr. A second trap from Docling's docs: *"torch on PP-OCRv5 supports ONLY chinese."*

Because the model revision changes the output, it is part of `ocr_cfg_version` (`SKILL.md`, non-negotiables) — a v5→v6 move must mint new source versions.

## What could not be verified

- **CER/WER from any official source** for Tesseract, EasyOCR, RapidOCR or Surya. None of these projects publish it. The only CER/WER found was a single-author preprint on retail receipts — not a general benchmark, deliberately not quoted.
- Any **OCRBench** score for these engines — OCRBench evaluates multimodal LLMs, and none of them appear.
- **Official latency** for Tesseract, EasyOCR or RapidOCR. RapidOCR exposes instrumentation (`elapse_list`) but publishes no benchmark table; the PP-OCRv6 ONNX/OpenVINO columns are the defensible proxy since RapidOCR runs the same graphs.
- **Container image sizes** for any engine. Wheel sizes are verified; image sizes are not, and are not asserted anywhere in this skill.
- **Weights licences for EasyOCR and docTR** — both genuinely unstated upstream. Treated as disqualifying on their own.

## Worth re-checking later

**PaddleOCR-VL-1.6** is the one candidate that could displace the default: top of OmniDocBench at 96.34, Apache-2.0 on **both** code and weights, ~0.96B parameters, 109 languages, and the only top-ranked VLM with documented x64 CPU and llama.cpp support. What blocks it today is integration, not licence or quality: no Docling backend, no published CPU latency, and it would duplicate the layout and table work Docling already owns. Revisit if Docling adds a backend, or if an eval run shows OCR quality is the binding constraint on answer accuracy.
