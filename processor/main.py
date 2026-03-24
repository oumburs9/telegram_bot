#!/usr/bin/env python3
import argparse
import io
import sys
from pathlib import Path

import fitz
from PIL import Image, ImageOps
from reportlab.lib.pagesizes import A4
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Generate printable variants from a PDF or image input")
    parser.add_argument("--input", required=True, help="Absolute path to input file")
    parser.add_argument("--output", required=True, help="Absolute path to output directory")
    return parser.parse_args()


def load_image(input_path: Path) -> Image.Image:
    suffix = input_path.suffix.lower()

    if suffix == ".pdf":
        document = fitz.open(str(input_path))

        if document.page_count < 1:
            document.close()
            raise ValueError("PDF contains no pages")

        page = document.load_page(0)
        pixmap = page.get_pixmap(dpi=300, alpha=False)
        image = Image.frombytes("RGB", (pixmap.width, pixmap.height), pixmap.samples)
        document.close()
        return image

    image = Image.open(input_path)
    return image


def normalize_image(source_image: Image.Image) -> Image.Image:
    normalized = ImageOps.exif_transpose(source_image)
    return normalized.convert("RGB")


def save_png_outputs(normalized: Image.Image, output_dir: Path) -> tuple[Path, Path]:
    normal_path = output_dir / "normal.png"
    mirror_path = output_dir / "mirror.png"

    normalized.save(normal_path, format="PNG")
    ImageOps.mirror(normalized).save(mirror_path, format="PNG")

    return normal_path, mirror_path


def draw_image_to_a4(image: Image.Image, output_path: Path, grayscale: bool) -> None:
    page_width, page_height = A4
    margin = 28.35

    render_image = image.convert("L").convert("RGB") if grayscale else image

    image_width, image_height = render_image.size
    max_width = page_width - (margin * 2)
    max_height = page_height - (margin * 2)

    scale = min(max_width / image_width, max_height / image_height)
    target_width = image_width * scale
    target_height = image_height * scale

    x = (page_width - target_width) / 2
    y = (page_height - target_height) / 2

    image_buffer = io.BytesIO()
    render_image.save(image_buffer, format="PNG")
    image_buffer.seek(0)

    pdf_canvas = canvas.Canvas(str(output_path), pagesize=A4)
    pdf_canvas.drawImage(ImageReader(image_buffer), x, y, width=target_width, height=target_height)
    pdf_canvas.showPage()
    pdf_canvas.save()


def save_pdf_outputs(normalized: Image.Image, output_dir: Path) -> tuple[Path, Path]:
    color_pdf_path = output_dir / "a4_color.pdf"
    gray_pdf_path = output_dir / "a4_gray.pdf"

    draw_image_to_a4(normalized, color_pdf_path, grayscale=False)
    draw_image_to_a4(normalized, gray_pdf_path, grayscale=True)

    return color_pdf_path, gray_pdf_path


def ensure_paths(input_path: Path, output_dir: Path) -> None:
    if not input_path.exists() or not input_path.is_file():
        raise FileNotFoundError("Input file does not exist")

    output_dir.mkdir(parents=True, exist_ok=True)


def main() -> int:
    args = parse_args()

    input_path = Path(args.input).expanduser().resolve()
    output_dir = Path(args.output).expanduser().resolve()

    try:
        ensure_paths(input_path, output_dir)
        source_image = load_image(input_path)
        normalized_image = normalize_image(source_image)
        save_png_outputs(normalized_image, output_dir)
        save_pdf_outputs(normalized_image, output_dir)
    except Exception as exc:
        print(f"processor_error: {exc}", file=sys.stderr)
        return 1

    return 0


if __name__ == "__main__":
    sys.exit(main())
