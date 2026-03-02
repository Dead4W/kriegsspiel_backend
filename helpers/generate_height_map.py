import rasterio
import requests
import numpy as np

from rasterio.warp import reproject, Resampling
from pyproj import Transformer
from PIL import Image

# Generate Height map from .tiff file


TIFF_PATH = "default_map.tiff"
API_KEY = "PASTE_YOUR_API_KEY_HERE" # get from https://opentopography.org/


# -------------------------
# read TIFF
# -------------------------

with rasterio.open(TIFF_PATH) as src:

    bounds = src.bounds
    crs = src.crs

    width = src.width
    height = src.height

    transform = src.transform


# -------------------------
# bounds -> WGS84
# -------------------------

transformer = Transformer.from_crs(
    crs,
    "EPSG:4326",
    always_xy=True
)

west, south = transformer.transform(
    bounds.left,
    bounds.bottom
)

east, north = transformer.transform(
    bounds.right,
    bounds.top
)


# -------------------------
# download DEM
# -------------------------

url = "https://portal.opentopography.org/API/globaldem"

params = dict(

    demtype="SRTMGL1",

    south=south,
    north=north,

    west=west,
    east=east,

    outputFormat="GTiff",

    API_Key=API_KEY
)

print("Downloading DEM...")

r = requests.get(url, params=params)

open("dem.tif", "wb").write(r.content)


# -------------------------
# reprojection
# -------------------------

with rasterio.open("dem.tif") as dem:

    src_data = dem.read(1)

    destination = np.zeros(
        (height, width),
        dtype=np.float32
    )

    reproject(

        source=src_data,

        destination=destination,

        src_transform=dem.transform,
        src_crs=dem.crs,

        dst_transform=transform,
        dst_crs=crs,

        resampling=Resampling.bilinear
    )


print("Reproject done")


# -------------------------
# clean nodata
# -------------------------

destination = np.nan_to_num(destination, nan=0)


# -------------------------
# normalize height
# пример:
# 1 пиксель = 1 метр
# -------------------------

height_int = np.clip(
    destination,
    0,
    765
).astype(np.uint16)


# -------------------------
# additive RGB encoding
# -------------------------

B = np.minimum(height_int, 255)

remaining = height_int - B

G = np.minimum(remaining, 255)

remaining = remaining - G

R = np.minimum(remaining, 255)


rgb = np.dstack((

    R.astype(np.uint8),
    G.astype(np.uint8),
    B.astype(np.uint8)

))


print("Encoding done")


# -------------------------
# PNG SAVE
# compress 1-2 как хотел
# -------------------------

img = Image.fromarray(rgb, "RGB")

img.save(

    "height_map.png",

    compress_level=2,   # fast compression
    optimize=False

)

print("Saved height_map.png")
