"""Build/check one pinned white-alpha caption for the public default Dispo number.

This asset renders only '+49 160 1881839'. It must never be substituted for
another configured telephone number. The caller owns exact source/href
admission; this generator changes no layout or settings. Arial bold 13px is
rasterized at 2x using the same pinned native outline toolchain as the captions.
"""
from __future__ import annotations
import argparse,hashlib,io,json
from pathlib import Path
import cairosvg,fontTools
from fontTools.pens.boundsPen import BoundsPen
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.ttLib import TTFont
from PIL import Image,__version__ as PILLOW_VERSION

APP=Path(__file__).absolute().parents[2]
NAME='outlook-dispo-default-phone-alpha-v1.png'
TEXT='+49 160 1881839'
HREF='tel:+491601881839'
FONT_SHA='766f06ac8761f82f25d032a220e89438f6064591af9915061f20b949efdedf69'

def sha(value:bytes)->str:return hashlib.sha256(value).hexdigest()
def render(font_dir:Path)->bytes:
    if (PILLOW_VERSION,cairosvg.__version__,fontTools.__version__)!=('12.2.0','2.9.0','4.63.0'):raise ValueError('Pinned native raster runtime changed')
    path=font_dir/'arialbd.ttf'
    if sha(path.read_bytes())!=FONT_SHA:raise ValueError('Approved Arial bold outline changed')
    with TTFont(path) as font:
        glyphs,cmap=font.getGlyphSet(),font.getBestCmap();scale=13/font['head'].unitsPerEm
        pen,bounds=SVGPathPen(glyphs),BoundsPen(glyphs);advance=0
        for char in TEXT:
            glyph=glyphs[cmap[ord(char)]];transform=(1,0,0,1,advance,0)
            glyph.draw(TransformPen(pen,transform));glyph.draw(TransformPen(bounds,transform));advance+=glyph.width
        left,bottom,right,top=bounds.bounds
        rectangle=[left*scale,14-top*scale,right*scale,14-bottom*scale]
        if min(rectangle)<0 or rectangle[2]>108 or rectangle[3]>20:raise ValueError('Phone caption outline would be clipped')
        svg='<svg xmlns="http://www.w3.org/2000/svg" width="108" height="20" viewBox="0 0 108 20"><path fill="#ffffff" transform="translate(0 14) scale('+f'{scale:.12f} {-scale:.12f}'+')" d="'+pen.getCommands()+'"/></svg>'
    raster=cairosvg.svg2png(bytestring=svg.encode(),output_width=216,output_height=40)
    alpha=Image.open(io.BytesIO(raster)).getchannel('A');image=Image.new('RGBA',alpha.size,(255,255,255,0));image.putalpha(alpha)
    if alpha.getextrema()!=(0,255) or not alpha.getbbox():raise ValueError('Genuine foreground transparency required')
    stream=io.BytesIO();image.save(stream,format='PNG',optimize=True,compress_level=9)
    return stream.getvalue()
def immutable(path:Path,value:bytes,check:bool)->int:
    if path.is_symlink() or path.parent.is_symlink():raise ValueError('No symlink outputs')
    if path.exists():
        if path.read_bytes()!=value:raise ValueError('Immutable asset differs: '+str(path))
        return 0
    if check:raise ValueError('Immutable asset missing: '+str(path))
    path.parent.mkdir(parents=True,exist_ok=True)
    with path.open('xb') as stream:stream.write(value)
    return 1
def main()->int:
    parser=argparse.ArgumentParser(description=__doc__);parser.add_argument('--check',action='store_true');parser.add_argument('--font-dir',type=Path,default=Path('C:/Windows/Fonts'));args=parser.parse_args()
    try:
        before={p:sha(p.read_bytes()) for directory in ('public/mail-assets','public/m','resources/mail-templates/assets','resources/mail-assets') for p in (APP/directory).iterdir() if p.is_file()}
        value=render(args.font_dir);digest=sha(value);url='/m/'+digest[:10]+'.png'
        created=sum(immutable(APP/directory/NAME,value,args.check) for directory in ('resources/mail-assets','resources/mail-templates/assets','public/mail-assets'))
        created+=immutable(APP/'public'/url.lstrip('/'),value,args.check)
        if any(sha(p.read_bytes())!=expected for p,expected in before.items()):raise ValueError('A historical asset changed')
        image=Image.open(io.BytesIO(value));pixels=list(image.get_flattened_data())
        print(json.dumps({'schema':'outlook-default-dispatch-phone-alpha-v1','filename':NAME,'url':url,'assetUrl':'/mail-assets/'+NAME,'sha256':digest,'bytes':len(value),'mime':'image/png','dimensions':[216,40],'display':[108,20],'text':TEXT,'exactHref':HREF,'foreground':'#ffffff','alphaExtrema':image.getchannel('A').getextrema(),'alphaBbox':image.getchannel('A').getbbox(),'transparentPixels':sum(p[3]==0 for p in pixels),'opaquePixels':sum(p[3]==255 for p in pixels),'antialiasedPixels':sum(0<p[3]<255 for p in pixels),'allPixelsPureWhite':all(p[:3]==(255,255,255) for p in pixels),'historicalAssetsUnchanged':True,'mode':'check' if args.check else 'build','created':created},indent=2))
    except (OSError,ValueError) as error:parser.exit(1,'Default phone caption build failed: '+str(error)+'\n')
    return 0
if __name__=='__main__':raise SystemExit(main())
