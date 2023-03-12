package main

import (
	"fmt"
	"image"
	"image/color"
	"image/png"
	"os"
	"path/filepath"

	"github.com/nfnt/resize"
)

func main() {
	background, err := os.Open(os.Args[1]);
	if err != nil {
		panic(err)
	}
	defer background.Close();

	bimg, err := png.Decode(background)
	if err != nil {
		panic(err)
	}

	rbimg := resize.Resize(2048, 2048, bimg, resize.Bicubic);

	foreground, err := os.Open(os.Args[2]);
	if err != nil {
		panic(err)
	}
	defer foreground.Close();

	fimg, err := png.Decode(foreground)
	if err != nil {
		panic(err)
	}

	rfimg := resize.Resize(2048, 2048, fimg, resize.Bicubic);

	canvas := image.NewRGBA(image.Rect(0, 0, 2048, 2048))

	for x := 0; x < 2048; x++ {
		for y := 0; y < 2048; y++ {
			fr, fg, fb, _ := rfimg.At(x, y).RGBA()
			_, _, _, ba := rbimg.At(x, y).RGBA()
			canvas.SetRGBA(x, y, color.RGBA{R: uint8(fr), G: uint8(fg), B: uint8(fb), A: uint8(ba)})
		}
	}

	os.MkdirAll(filepath.Dir(os.Args[3]), 0777)

	oimg, err := os.OpenFile(os.Args[3], os.O_CREATE|os.O_RDWR|os.O_TRUNC, 0777)
	if err != nil {
		panic(err)
	}
	defer oimg.Close()

	encoder := png.Encoder{
		CompressionLevel: png.BestCompression,
	}
	if err := encoder.Encode(oimg, canvas); err != nil {
		panic(err)
	}

	fmt.Println("ok")
}